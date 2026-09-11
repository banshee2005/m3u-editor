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
use App\Models\PlaylistAuth;
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
            $recording->update([
                'status' => DvrRecordingStatus::Failed->value,
                'error_message' => 'Server restarted during recording',
                'proxy_network_id' => null,
            ]);

            $playlist = $recording->playlistAuth?->playlist()
                ?? $recording->dvrSetting?->owner();
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
    public function start(DvrRecording $recording, bool $forceFresh = false): void
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
            $rawUrl = $channel->url_custom ?? $channel->url ?? $recording->stream_url;

            // When the channel's source playlist pools provider profiles, resolve the
            // URL through the proxy so a provider profile is selected and its capacity
            // reserved - a DVR recording should draw from the pool exactly as a live
            // viewer does. Fall back to the raw channel URL if resolution fails so a
            // recording is never lost to a transient proxy/provider error.
            $sourcePlaylist = $channel->playlist;
            if ($sourcePlaylist instanceof Playlist && ($sourcePlaylist->profiles_enabled || $sourcePlaylist->enable_proxy)) {
                // Snapshot the active live streams so we can detect which ones a
                // "DVR wins" eviction stopped and notify the affected viewers.
                // Live streams created via a merged/custom wrapper carry the
                // WRAPPER's playlist_uuid (recordings' streams carry the source
                // uuid), so match on original_playlist_uuid — upstream keys pool
                // lookups on the source uuid, and both variants set it.
                $allStreams = $this->proxy->getActiveLiveStreams();
                $streamsBefore = array_values(array_filter(
                    $allStreams,
                    fn ($s) => ($s['metadata']['original_playlist_uuid'] ?? $s['metadata']['playlist_uuid'] ?? null) === $sourcePlaylist->uuid,
                ));

                // Pre-evict the oldest LIVE-VIEWER stream (never a recording's
                // stream) when the pool is full, so getChannelUrl's age-based
                // stopOldestOnLimit cannot kill an active recording's stream.
                $totalProfileSlots = 0;
                foreach ($sourcePlaylist->profiles()->where('enabled', true)->get() as $profile) {
                    $totalProfileSlots += (int) $profile->effective_max_streams;
                }

                if ($totalProfileSlots > 0 && count($streamsBefore) >= $totalProfileSlots) {
                    $recordingChannelIds = $setting->recordings()
                        ->where('status', DvrRecordingStatus::Recording)
                        ->pluck('channel_id')
                        ->map(fn ($c) => (int) $c)
                        ->all();

                    Log::info('DVR: pool full, evaluating pre-eviction', [
                        'recording_id' => $recording->id,
                        'streams' => array_map(fn ($s) => [
                            'id' => substr($s['stream_id'], 0, 8),
                            'channel_id' => $s['metadata']['channel_id'] ?? null,
                            'created' => $s['created_at'],
                        ], $streamsBefore),
                        'recording_channel_ids' => $recordingChannelIds,
                        'total_slots' => $totalProfileSlots,
                    ]);

                    // Oldest first — a viewer whose stream is evicted should be
                    // the one who has been watching the longest.
                    usort($streamsBefore, fn ($a, $b) => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));

                    $preEvictedStreamId = null;
                    foreach ($streamsBefore as $candidate) {
                        $streamChannelId = (int) ($candidate['metadata']['channel_id'] ?? 0);
                        if (in_array($streamChannelId, $recordingChannelIds, true)) {
                            continue;
                        }

                        if ($this->proxy->deleteStream($candidate['stream_id'])) {
                            $preEvictedStreamId = $candidate['stream_id'];
                            Log::info('DVR: evicted oldest live stream for recording', [
                                'recording_id' => $recording->id,
                                'title' => $recording->title,
                                'evicted_stream_id' => $candidate['stream_id'],
                                'evicted_channel_id' => $streamChannelId,
                            ]);
                            $this->notifyEvictedViewer($candidate, $recording);
                            usleep(200000); // 200ms for the proxy to release the slot
                        }
                        break;
                    }
                }

                try {
                    $streamUrl = $this->proxy->getChannelUrl(
                        $sourcePlaylist,
                        $channel,
                        null,
                        null,
                        $recording->user?->name,
                    );
                    if (empty($streamUrl)) {
                        $streamUrl = $rawUrl;
                    }
                } catch (\Throwable $e) {
                    // getChannelUrl() aborts (503) when every provider profile is at
                    // capacity. "DVR wins": swallow that and fall back to the raw
                    // primary-account URL so the recording is never lost, relying on
                    // the fact that most providers boot an older stream when a new
                    // one starts. (When proxy_stop_oldest_on_limit is enabled,
                    // getChannelUrl evicts the oldest live stream instead.)
                    Log::warning('DVR: pooled-provider URL resolution failed, using raw channel URL', [
                        'recording_id' => $recording->id,
                        'exception' => $e->getMessage(),
                    ]);
                    $streamUrl = $rawUrl;
                }

                // Notify the viewers of any live stream the DVR-wins eviction
                // stopped while resolving this recording's URL (the pre-evicted
                // stream was already notified above — skip it here).
                $allStreamsAfter = $this->proxy->getActiveLiveStreams();
                $streamsAfter = array_values(array_filter(
                    $allStreamsAfter,
                    fn ($s) => ($s['metadata']['original_playlist_uuid'] ?? $s['metadata']['playlist_uuid'] ?? null) === $sourcePlaylist->uuid,
                ));
                foreach ($streamsBefore as $before) {
                    if (isset($preEvictedStreamId) && ($before['stream_id'] ?? null) === $preEvictedStreamId) {
                        continue;
                    }
                    $stillActive = collect($streamsAfter)->first(
                        fn ($after) => ($after['stream_id'] ?? null) === ($before['stream_id'] ?? null),
                    );
                    if ($stillActive === null) {
                        $this->notifyEvictedViewer($before, $recording);
                    }
                }
            } else {
                $streamUrl = $rawUrl;
            }
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
        //
        // On a retry ($forceFresh) the previous attempt may have piggybacked
        // onto a pooled stream whose upstream went stale — reuse would fail
        // again, so resolve a fresh stream instead.
        $playlistUuid = $channel?->getEffectivePlaylist()?->uuid;
        if (! $forceFresh && $channel && $playlistUuid) {
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

        Log::info('DVR: Starting proxy broadcast', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'stream_url' => $streamUrl,
        ]);

        $networkId = $this->proxy->startDvrBroadcast($recording, $setting, $streamUrl);

        $recording->update([
            'status' => DvrRecordingStatus::Recording->value,
            'actual_start' => now(),
            'proxy_network_id' => $networkId,
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
     * Notify the viewer whose live stream was evicted to make room for a DVR
     * recording ("DVR wins" policy). Uses the stream metadata to target the
     * owning playlist auth's devices specifically.
     *
     * @param  array{stream_id: string, metadata: array}  $stream
     */
    protected function notifyEvictedViewer(array $stream, DvrRecording $recording): void
    {
        $metadata = $stream['metadata'] ?? [];
        // Live streams created via a merged/custom wrapper carry the WRAPPER's
        // uuid in playlist_uuid (a MergedPlaylist/CustomPlaylist, not a
        // Playlist). original_playlist_uuid is always the real Playlist uuid.
        $playlistUuid = $metadata['original_playlist_uuid'] ?? $metadata['playlist_uuid'] ?? null;
        $playlistAuthId = isset($metadata['playlist_auth_id'])
            ? (int) $metadata['playlist_auth_id']
            : null;

        Log::info('DVR: live stream evicted for recording', [
            'recording_id' => $recording->id,
            'title' => $recording->title,
            'evicted_stream_id' => $stream['stream_id'],
            'playlist_uuid' => $playlistUuid,
            'playlist_auth_id' => $playlistAuthId,
        ]);

        // Target the notifiable the evicted viewer's app actually queries: the
        // playlist the viewer authenticated through (for merged credentials,
        // the MergedPlaylist — the source playlist would be invisible to the
        // viewer's notification scope).
        $playlistAuth = $playlistAuthId
            ? PlaylistAuth::find($playlistAuthId)
            : null;

        $playlist = $playlistAuth?->playlist()
            ?? ($playlistUuid ? Playlist::where('uuid', $playlistUuid)->first() : null);
        if (! $playlist) {
            return;
        }

        AppNotification::make()
            ->title(__('DVR Recording Started'))
            ->body(__('A DVR recording has taken precedence and stopped your live stream.'))
            ->status('warning')
            ->tvBroadcast($playlist, 'dvr', false, $playlistAuth);
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
            $this->proxy->cleanupDvrBroadcast($recording->proxy_network_id);
        }
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
