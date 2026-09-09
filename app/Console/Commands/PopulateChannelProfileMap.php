<?php

namespace App\Console\Commands;

use App\Models\ChannelProfileMap;
use App\Models\MergedPlaylist;
use Illuminate\Console\Command;

class PopulateChannelProfileMap extends Command
{
    protected $signature = 'channel-profile-map:populate {--merged-playlist-id=}';

    public function handle(): int
    {
        $query = MergedPlaylist::with('playlists');
        if ($this->option('merged-playlist-id')) {
            $query->where('id', $this->option('merged-playlist-id'));
        }

        foreach ($query->get() as $mp) {
            $this->info("Populating mappings for: {$mp->name} (#{$mp->id})");
            $mp->populateChannelProfileMaps();
        }

        $total = ChannelProfileMap::count();
        $this->info("Total mappings: {$total}");

        return 0;
    }
}
