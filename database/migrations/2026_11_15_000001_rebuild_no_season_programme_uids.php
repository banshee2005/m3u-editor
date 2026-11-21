<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * buildProgrammeUid() now includes start_time for programmes without
     * season/episode (so multiple same-title draws on one day are distinct).
     * Rewrite existing no-S/E recording uids to the new format so active
     * scheduled rows still dedup against the recomputed uid.
     */
    public function up(): void
    {
        $rows = DB::table('dvr_recordings')
            ->whereNull('season')
            ->whereNull('episode')
            ->whereNotNull('programme_uid')
            // epg_channel_id is not a column -- it lives inside the
            // epg_programme_data JSON, decoded below.
            ->get(['id', 'title', 'programme_start', 'epg_programme_data', 'programme_uid']);

        foreach ($rows as $row) {
            $data = is_string($row->epg_programme_data)
                ? (json_decode($row->epg_programme_data, true) ?: [])
                : (array) ($row->epg_programme_data ?? []);
            $epgChannelId = $data['epg_channel_id'] ?? null;

            if ($epgChannelId === null || $row->programme_start === null || $row->title === null) {
                continue;
            }

            // programme_start is stored as UtcDateTime ('Y-m-d H:i:sP');
            // format('Y-m-d H:i:s') matches EpgProgramme::start_time->format().
            $start = date('Y-m-d H:i:s', strtotime($row->programme_start));
            $newUid = md5(sprintf('%s|%s|%s', $epgChannelId, $row->title, $start));

            if ($newUid !== $row->programme_uid) {
                DB::table('dvr_recordings')
                    ->where('id', $row->id)
                    ->update(['programme_uid' => $newUid]);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Cannot restore the old channel|title|S/E-only uids without the
     * original programme row; leave rewritten uids in place (they still
     * dedup correctly under the old algorithm only if start is ignored,
     * which it no longer is - acceptable for a down migration).
     */
    public function down(): void
    {
        // Intentionally a no-op.
    }
};
