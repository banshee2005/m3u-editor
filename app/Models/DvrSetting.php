<?php

namespace App\Models;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrSeriesMode;
use App\Traits\HasPolymorphicPlaylistOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DvrSetting extends Model
{
    use HasFactory;
    use HasPolymorphicPlaylistOwner;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'playlist_id' => 'integer',
            'custom_playlist_id' => 'integer',
            'merged_playlist_id' => 'integer',
            'enabled' => 'boolean',
            'use_proxy' => 'boolean',
            'max_concurrent_recordings' => 'integer',
            'default_start_early_seconds' => 'integer',
            'default_end_late_seconds' => 'integer',
            'enable_metadata_enrichment' => 'boolean',
            'generate_nfo_files' => 'boolean',
            'enable_comskip' => 'boolean',
            'tmdb_api_key' => 'encrypted',
            'global_disk_quota_gb' => 'integer',
            'retention_days' => 'integer',
            'default_series_mode' => DvrSeriesMode::class,
            'default_series_keep_last' => 'integer',
            'include_disabled_channels' => 'boolean',
            'stream_profile_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    public function recordingRules(): HasMany
    {
        return $this->hasMany(DvrRecordingRule::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(DvrRecording::class);
    }

    /**
     * Total storage used by recordings under this DVR setting, in bytes.
     */
    public function getStorageUsedBytesAttribute(): int
    {
        return (int) $this->recordings()
            ->whereNotNull('file_size_bytes')
            ->sum('file_size_bytes');
    }

    /**
     * Resolve the effective start-early seconds for a recording rule.
     */
    public function resolveStartEarlySeconds(?int $ruleOverride): int
    {
        return $ruleOverride ?? $this->default_start_early_seconds;
    }

    /**
     * Resolve the effective end-late seconds for a recording rule.
     */
    public function resolveEndLateSeconds(?int $ruleOverride): int
    {
        return $ruleOverride ?? $this->default_end_late_seconds;
    }

    /**
     * Check if the DVR is at concurrent recording capacity.
     *
     * Counts ONLY recordings that are actively consuming a provider
     * connection (status = Recording). Scheduled rules are plans, not
     * connections — the scheduler enforces capacity again at start time
     * (via $pendingInTick) so over-scheduling is harmless.
     *
     * PostProcessing recordings are excluded: the upstream connection is
     * already released and only local transcoding remains.
     *
     * @param  int  $pendingInTick  Recordings dispatched to start earlier in
     *                              this scheduler tick but not yet flipped to
     *                              Recording. Prevents one tick from starting
     *                              more than there are free slots.
     */
    public function isAtCapacity(int $pendingInTick = 0): bool
    {
        $active = $this->recordings()
            ->where('status', DvrRecordingStatus::Recording)
            ->count();

        return ($active + $pendingInTick) >= $this->max_concurrent_recordings;
    }
}
