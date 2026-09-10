<?php

namespace App\Services;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Jobs\PostProcessDvrRecording;
use App\Jobs\SendPushNotificationRelay;
use App\Models\CustomPlaylist;
use App\Models\DvrRecording;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\TvNotification;
use App\Notifications\Notification as AppNotification;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * DvrRecorderService - Delegates FFmpeg process management to the m3u-proxy.
 *
 * Responsibilities:
 * - Start a DVR broadcast on the proxy (via BroadcastManager)
 * - Stop a running broadcast via the proxy API
 * - Recover stale RECORDING rows on app boot
 *
 * The proxy handles all FFmpeg lifecycle, HLS segment preservation (dvr_mode),
 * hardware acceleration, and post-recording callbacks. No PIDs or sleep loops here.
 */
class DvrRecorderService
{
    public function __construct(protected M3uProxyService $proxy) {}

    /**
     * Recover from a crash by marking any stale RECORDING rows as FAILED.
     * Called once on application boot.
     *
     * Each stale recording is updated individually so DvrRecordingStatusEvent
     * fires for the live TV app. One aggregated TvNotification and one
     * SendPushNotificationRelay are created per effective playlist.
     */
    public function recoverFromCrash(): void
    {
        $stale = DvrRecording::recording()
            ->with('dvrSetting.playlist', 'dvrSetting.customPlaylist', 'dvrSetting.mergedPlaylist')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        Log::warning('DVR crash recovery: marking stale RECORDING entries as FAILED', [
            'count' => $stale->count(),
        ]);

        /** @var array<string, array{playlist: Playlist|CustomPlaylist|MergedPlaylist, count: int}> $byPlaylist */
        $byPlaylist = [];

        foreach ($stale as $recording) {
            $this->releaseProfileReservation($recording);

            // Stop the proxy-side broadcast BEFORE clearing proxy_network_id.
            // Otherwise the FFmpeg process keeps running after a restart and
            // holds the provider/tuner slot for hours (the editor only knows
            // the row is dead, not the broadcast) — which is exactly how a
            // "5th recording" gets a 503 and existing recordings silently die.
            if ($recording->proxy_network_id) {
                $this->proxy->stopDvrBroadcast($recording->proxy_network_id);
                $this->proxy->cleanupDvrBroadcast($recording->proxy_network_id);
            }

            $recording->update([
                'status' => DvrRecordingStatus::Failed->value,
                'error_message' => 'Server restarted during recording',
                'proxy_network_id' => null,
            ]);

            $playlist = $recording->dvrSetting?->owner();
            if ($playlist) {
                $playlistKey = $playlist->getMorphClass().':'.$playlist->id;
                $byPlaylist[$playlistKey] ??= ['playlist' => $playlist, 'count' => 0];
                $byPlaylist[$playlistKey]['count']++;
            }
        }

        foreach ($byPlaylist as $entry) {
            /** @var Playlist|CustomPlaylist|MergedPlaylist $playlist */
            $playlist = $entry['playlist'];
            $count = $entry['count'];
            $label = $count === 1
                ? __('Recording Failed')
                : __(':count recordings failed', ['count' => $count]);

            AppNotification::make()
                ->title($label)
                ->body(__('Server restarted during recording'))
                ->status('danger')
                ->tvBroadcast($playlist, 'dvr');
        }
    }

    /**
     * Start recording by launching a DVR broadcast on the proxy.
     *
     * The proxy manages the FFmpeg process, preserves all HLS segments (dvr_mode=true),
     * and calls back the editor when the recording ends or fails.
     */
    public function start(DvrRecording $recording): void
    {
        if ($recording->status !== DvrRecordingStatus::Scheduled) {
            Log::warning('DVR start skipped - recording not in SCHEDULED state', [
                'recording_id' => $recording->id,
                'status' => $recording->status->value,
            ]);

            return;
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            throw new Exception("DvrSetting not found for recording {$recording->id}");
        }

        // Resolve the raw source URL for the recording.
        // If there's already an active stream on the proxy for this channel, piggyback
        // off it by using the stream's local proxy URL as the broadcast source. This
        // avoids creating a second upstream connection to the provider, which can cause
        // circuit-breaker drops (provider kills one connection when a second opens).
        $channel = $recording->channel;
        if ($channel) {
            $streamUrl = $channel->url_custom ?? $channel->url ?? $recording->stream_url;
        } else {
            $streamUrl = $recording->stream_url;
        }

        if (empty($streamUrl)) {
            throw new Exception("Recording {$recording->id} has no stream_url - cannot start");
        }

        // Check if there's an existing stream for this channel to piggyback off.
        // Use the channel's own real source playlist (not the DvrSetting's owner,
        // which may be a Custom/Merged playlist spanning multiple real playlists)
        // since that's what the proxy actually keys active streams by.
        $playlistUuid = $channel?->getEffectivePlaylist()?->uuid;
        if ($channel && $playlistUuid) {
            $activeStreamId = $this->proxy->getActiveStreamIdForChannel($channel->id, $playlistUuid);
            if ($activeStreamId) {
                $proxyUrl = $this->proxy->getStreamProxyUrl($activeStreamId);
                Log::info('DVR: Piggybacking off existing stream', [
                    'recording_id' => $recording->id,
                    'stream_id' => $activeStreamId,
                    'proxy_url' => $proxyUrl,
                ]);
                $streamUrl = $proxyUrl;
            }
        }

        if ($streamUrl !== $recording->stream_url) {
            $recording->stream_url = $streamUrl;
            $recording->saveQuietly();
        }

        // Provider-connection capacity guard. If this recording needs a NEW
        // provider connection (no piggyback), verify the source account has a
        // free slot BEFORE opening it. Opening a connection past the provider's
        // limit can trip a circuit breaker / tuner eviction that kills EXISTING
        // recordings — we must refuse here instead of letting the provider evict.
        if (! $activeStreamId && $channel?->playlist instanceof Playlist) {
            $sourcePlaylist = $channel->playlist;
            $limit = (int) $sourcePlaylist->available_streams;

            if ($limit > 0) {
                // Count DISTINCT active channels (a channel that is both watched
                // live AND recorded consumes ONE provider connection via piggyback).
                $activeDvrChannelIds = $setting->recordings()
                    ->where('status', DvrRecordingStatus::Recording)
                    ->where('id', '!=', $recording->id)
                    ->pluck('channel_id')
                    ->map(fn ($c) => (int) $c)
                    ->unique()
                    ->values()
                    ->all();

                $liveIds = M3uProxyService::getActiveLiveChannelIds($sourcePlaylist->uuid);
                $mergedUuids = \Illuminate\Support\Facades\DB::table('merged_playlist_playlist as mpp')
                    ->join('merged_playlists as mp', 'mp.id', '=', 'mpp.merged_playlist_id')
                    ->where('mpp.playlist_id', $sourcePlaylist->id)
                    ->pluck('mp.uuid');
                foreach ($mergedUuids as $mergedUuid) {
                    if ($mergedUuid !== $sourcePlaylist->uuid) {
                        $liveIds = array_merge($liveIds, M3uProxyService::getActiveLiveChannelIds((string) $mergedUuid));
                    }
                }

                $activeChannelIds = array_values(array_unique(array_merge($activeDvrChannelIds, array_map('intval', $liveIds))));
                $distinctActive = count($activeChannelIds);

                // This recording needs a NEW connection UNLESS its channel is
                // already active (it piggybacks on the existing live stream).
                $willPiggyback = in_array((int) $recording->channel_id, $activeChannelIds, true);
                $projected = $distinctActive + ($willPiggyback ? 0 : 1);

                if ($projected > $limit) {
                    Log::warning('DVR: Refusing to start recording — provider at capacity', [
                        'recording_id' => $recording->id,
                        'title' => $recording->title,
                        'playlist_id' => $sourcePlaylist->id,
                        'available_streams' => $limit,
                        'distinct_active' => $distinctActive,
                        'projected' => $projected,
                        'will_piggyback' => $willPiggyback,
                    ]);

                    $recording->update([
                        'status' => DvrRecordingStatus::Failed->value,
                        'actual_end' => now(),
                        'error_message' => 'Provider at capacity — cannot start recording without evicting an existing one.',
                    ]);

                    return;
                }
            }
        }

        // Provider-profile mapping (same mechanism as live playback): playlists
        // with profiles_enabled (e.g. "2 Step 2 Provider") distribute recordings
        // across provider accounts (Primary/Secondary) instead of piling every
        // recording onto the raw Primary URL, which overflows a single provider's
        // stream limit and makes subsequent recordings stuck at "Scheduled" and
        // live playback fail with a generic "Source error".
        $selectedProfile = null;
        $reservationId = null;
        if ($channel && $channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled) {
            $profileSourcePlaylist = $channel->playlist;
            $forceSelect = $profileSourcePlaylist->bypass_provider_limits ?? false;
            [$selectedProfile, $reservationId] = ProfileService::selectAndReserveProfile(
                $profileSourcePlaylist,
                null,
                (int) $channel->id,
                (string) ($channel->getEffectivePlaylist()?->uuid ?? $profileSourcePlaylist->uuid),
                $forceSelect,
                null,
                'channel',
            );

            if (! $selectedProfile) {
                Log::warning('DVR: No provider profile available — refusing to start recording', [
                    'recording_id' => $recording->id,
                    'title' => $recording->title,
                    'playlist_id' => $profileSourcePlaylist->id,
                ]);

                $recording->update([
                    'status' => DvrRecordingStatus::Failed->value,
                    'actual_end' => now(),
                    'error_message' => 'No provider profiles available — cannot start recording without evicting an existing one.',
                ]);

                return;
            }

            $streamUrl = PlaylistUrlService::getChannelUrl($channel, $selectedProfile);

            Log::info('DVR: Selected provider profile for recording', [
                'recording_id' => $recording->id,
                'title' => $recording->title,
                'provider_profile_id' => $selectedProfile->id,
                'provider_profile_name' => $selectedProfile->name,
                'stream_url' => $streamUrl,
            ]);
        }

        Log::info('DVR: Starting proxy broadcast', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'stream_url' => $streamUrl,
        ]);

        try {
            $networkId = $this->proxy->startDvrBroadcast($recording, $setting, $streamUrl);
        } catch (Exception $e) {
            // The broadcast never started — release the reserved provider slot.
            if ($selectedProfile && $reservationId) {
                ProfileService::cancelReservation($selectedProfile, $reservationId);
            }

            throw $e;
        }

        $metadata = $recording->metadata ?? [];
        if ($selectedProfile && $reservationId) {
            $metadata['provider_profile_id'] = $selectedProfile->id;
            $metadata['provider_reservation_id'] = $reservationId;
        }

        $recording->update([
            'status' => DvrRecordingStatus::Recording->value,
            'actual_start' => now(),
            'proxy_network_id' => $networkId,
            'stream_url' => $streamUrl,
            'metadata' => $metadata,
            'attempt_count' => ($recording->attempt_count ?? 0) + 1,
        ]);

        Log::info('DVR: Proxy broadcast started', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $networkId,
            'title' => $recording->title,
        ]);

        if ($user = $recording->user) {
            Notification::make()
                ->success()
                ->title('Recording Started')
                ->body($recording->title)
                ->broadcast($user)
                ->sendToDatabase($user);
        }

        $recording->notifyTv(__('Recording Started'), 'info');
    }

    /**
     * Stop recording by signalling the proxy to terminate the broadcast.
     *
     * The proxy retains segment files after /stop, so post-processing can fetch
     * them via HTTP afterward. We do NOT clear proxy_network_id here - the
     * downloader needs it. It is cleared after successful cleanup in post-processing.
     */
    public function stop(DvrRecording $recording): void
    {
        $networkId = $recording->proxy_network_id;

        if (! $networkId) {
            Log::warning('DVR stop: no proxy_network_id on recording - assuming already stopped', [
                'recording_id' => $recording->id,
            ]);
            $this->finalizeStop($recording);

            return;
        }

        Log::info('DVR: Stopping proxy broadcast', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $networkId,
        ]);

        $this->proxy->stopDvrBroadcast($networkId);

        $this->releaseProfileReservation($recording);

        $this->finalizeStop($recording);
    }

    /**
     * Cancel a recording - stops the proxy broadcast (if any) and marks as cancelled.
     *
     * NOTE: We do NOT cleanup the proxy files here. The callback will fire when
     * FFmpeg actually stops, and post-processing will handle cleanup. This ensures
     * we don't delete segments that are still being written.
     *
     * A recording that had already started (proxy_network_id was set) has real
     * footage worth keeping — the user may choose to keep this recording rather
     * than discard it (see XtreamApiController::cancelDvrRecording / the TV app's
     * "Keep recording" option), so it goes through the same stop → post-processing
     * pipeline as a natural completion instead of being marked Cancelled directly.
     * It ends up Completed (playable) like any other recording; user_cancelled and
     * error_message are preserved through post-processing as a "stopped early" marker.
     * A recording that never started (still Scheduled) has nothing to process, so
     * it's marked Cancelled immediately as before.
     */
    public function cancel(DvrRecording $recording): void
    {
        $networkId = $recording->proxy_network_id;
        $hadFootage = (bool) $networkId;

        if ($networkId) {
            $this->proxy->stopDvrBroadcast($networkId);
            // Do NOT cleanup here - let the callback and post-processing handle it
            // so we don't delete segments that are still being written
        }

        $this->releaseProfileReservation($recording);

        // Delete "once" rules on cancel regardless of outcome below - they're one-shot.
        $rule = $recording->recordingRule;
        if ($rule && $rule->type === DvrRuleType::Once) {
            $rule->delete();

            Log::info('DVR cancel: deleted once-rule', [
                'recording_id' => $recording->id,
                'rule_id' => $rule->id,
            ]);
        }

        if (! $hadFootage) {
            $recording->update([
                'status' => DvrRecordingStatus::Cancelled->value,
                'actual_end' => now(),
                'proxy_network_id' => null,
                'error_message' => 'Cancelled by user',
                'user_cancelled' => true,
            ]);

            $recording->notifyTv(__('Recording Cancelled'), 'warning');

            return;
        }

        $recording->update([
            'actual_end' => now(),
            'error_message' => 'Cancelled by user',
            'user_cancelled' => true,
        ]);

        $recording->notifyTv(__('Recording Cancelled'), 'warning');

        $this->finalizeStop($recording);
    }

    /**
     * Release a recording's proxy-side broadcast/segment files without running
     * post-processing.
     *
     * PostProcessDvrRecording normally owns this cleanup (see
     * DvrPostProcessorService), but that job may never get the chance to run
     * on a recording that's about to be deleted - e.g. the TV app's "Delete
     * recording" choice calls cancel_dvr_recording then delete_dvr_recording
     * back-to-back, well before the (delayed/callback-triggered) job executes.
     * Call this before deleting a recording that still has a proxy_network_id
     * so those resources aren't leaked. Safe to call on a recording the job
     * already cleaned up - proxy_network_id will already be null by then.
     */
    public function releaseProxyResources(DvrRecording $recording): void
    {
        if ($recording->proxy_network_id) {
            // Stop a still-running broadcast BEFORE removing its record —
            // otherwise the FFmpeg process keeps holding the provider/tuner
            // slot after the row is gone (phantom broadcast that blocks
            // capacity for every subsequent recording on that pool).
            $this->proxy->stopDvrBroadcast($recording->proxy_network_id);
            $this->proxy->cleanupDvrBroadcast($recording->proxy_network_id);
        }

        // Free the provider-profile slot too — otherwise the reservation
        // leaks in Redis and the profile stays saturated forever.
        $this->releaseProfileReservation($recording);
    }

    /**
     * Release a recording's provider-profile reservation (if any).
     *
     * Profile-enabled playlists reserve a provider slot when the recording
     * starts. That slot must be freed when the broadcast ends, fails, or is
     * cancelled — otherwise the profile fills up with phantom reservations
     * and future recordings can never start.
     */
    public function releaseProfileReservation(DvrRecording $recording): void
    {
        $metadata = $recording->metadata ?? [];
        $profileId = $metadata['provider_profile_id'] ?? null;
        $reservationId = $metadata['provider_reservation_id'] ?? null;

        if (! $profileId || ! $reservationId) {
            return;
        }

        $profile = PlaylistProfile::find($profileId);
        if ($profile) {
            ProfileService::cancelReservation($profile, $reservationId);

            Log::info('DVR: Released provider profile reservation', [
                'recording_id' => $recording->id,
                'provider_profile_id' => $profileId,
                'reservation_id' => $reservationId,
            ]);
        }

        unset($metadata['provider_profile_id'], $metadata['provider_reservation_id']);
        $recording->updateQuietly(['metadata' => $metadata]);
    }

    /**
     * Transition a stopped recording to POST_PROCESSING and dispatch the concat job.
     *
     * proxy_network_id is intentionally preserved through post-processing so the
     * HLS downloader can fetch segments from the proxy. It will be cleared after
     * successful cleanup at the end of post-processing.
     *
     * The proxy sends a callback (programme_ended/recording_stopped) when FFmpeg
     * actually stops - that callback is the authoritative signal to dispatch
     * post-processing. This method's dispatch is a safety net: it only fires
     * after a long delay (60s) in case the callback is never received.
     */
    private function finalizeStop(DvrRecording $recording): void
    {
        if (in_array($recording->status, [
            DvrRecordingStatus::Cancelled,
            DvrRecordingStatus::Failed,
        ])) {
            return;
        }

        $recording->update([
            'status' => DvrRecordingStatus::PostProcessing->value,
        ]);

        // Safety net: if the proxy callback never arrives, fall back to post-processing
        // after 60s. By that point FFmpeg is definitely done flushing segments.
        // The callback (if it arrives) will dispatch immediately and this will be a no-op.
        PostProcessDvrRecording::dispatch($recording->id)
            ->onQueue('dvr-post')
            ->delay(now()->addSeconds(60));

        Log::info('DVR: Post-processing queued (fallback)', [
            'recording_id' => $recording->id,
            'proxy_network_id' => $recording->proxy_network_id,
        ]);
    }
}
