<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelProfileMap extends Model
{
    protected $table = 'channel_profile_map';

    protected $fillable = [
        'source_channel_id',
        'target_playlist_id',
        'target_channel_id',
    ];

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'source_channel_id');
    }

    public function targetPlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'target_playlist_id');
    }

    public function targetChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'target_channel_id');
    }

    /**
     * Find the target channel for a source channel on a specific target playlist.
     */
    public static function resolve(int $sourceChannelId, int $targetPlaylistId): ?self
    {
        return static::where('source_channel_id', $sourceChannelId)
            ->where('target_playlist_id', $targetPlaylistId)
            ->first();
    }

    /**
     * Auto-populate mappings for a source playlist's channels against a target playlist.
     * Matches by normalized channel name.
     */
    public static function populateForPlaylist(Playlist $sourcePlaylist, Playlist $targetPlaylist): int
    {
        $sourceChannels = $sourcePlaylist->channels()
            ->with('group')
            ->where('enabled', true)
            ->get();

        $targetChannels = $targetPlaylist->channels()
            ->with('group')
            ->where('enabled', true)
            ->get();

        // Group target channels by name → collection of candidates
        $targetByName = $targetChannels->groupBy(fn ($ch) => $ch->name);

        $created = 0;
        foreach ($sourceChannels as $sourceChannel) {
            if (empty($sourceChannel->name)) {
                continue;
            }

            $candidates = $targetByName->get($sourceChannel->name);
            if (! $candidates || $candidates->isEmpty()) {
                continue;
            }

            $targetChannel = null;

            if ($candidates->count() === 1) {
                // Unique name — direct match
                $targetChannel = $candidates->first();
            } else {
                // Multiple candidates — try to match by group name
                $sourceGroupName = is_object($sourceChannel->group) ? $sourceChannel->group->name : (string) ($sourceChannel->group ?? '');
                if ($sourceGroupName !== '') {
                    $targetChannel = $candidates->first(function ($ch) use ($sourceGroupName) {
                        $chGroup = is_object($ch->group) ? $ch->group->name : (string) ($ch->group ?? '');

                        return $chGroup === $sourceGroupName;
                    });
                }

                // Still ambiguous — skip to avoid incorrect mapping
                if (! $targetChannel) {
                    continue;
                }
            }

            static::updateOrCreate(
                [
                    'source_channel_id' => $sourceChannel->id,
                    'target_playlist_id' => $targetPlaylist->id,
                ],
                [
                    'target_channel_id' => $targetChannel->id,
                ]
            );
            $created++;
        }

        return $created;
    }
}
