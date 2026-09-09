<?php

use App\Models\Channel;
use App\Models\ChannelProfileMap;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistProfile;

test('it resolves directly to the target playlist channel url when the profile points at a local playlist', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => 'shared-source-id',
    ]);

    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'shared-source-id',
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/live/realuser/realpass/999.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe($targetChannel->url_custom ?? $targetChannel->url);
});

test('it resolves directly to the target playlist episode url when the profile points at a local playlist', function () {
    $poolPlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceEpisode = Episode::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_episode_id' => 555555,
    ]);

    $targetEpisode = Episode::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_episode_id' => 555555,
        'enabled' => true,
        'url' => 'http://real-upstream-provider.test/series/realuser/realpass/42.mkv',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    expect($profile->transformEpisodeUrl($sourceEpisode))->toBe($targetEpisode->url);
});

test('it leaves the credential swap path untouched for direct-to-provider profiles', function () {
    $playlist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'primaryuser',
            'password' => 'primarypass',
        ],
    ]);

    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://primary-provider.test/live/primaryuser/primarypass/123.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $playlist->id,
        'url' => 'http://secondary-provider.test',
        'username' => 'secondaryuser',
        'password' => 'secondarypass',
    ]);

    expect($profile->transformChannelUrl($channel))
        ->toBe('http://secondary-provider.test/live/secondaryuser/secondarypass/123.ts');
});

test('it falls back to the plain credential swap when an internal profile has no matching target channel', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => 'unmatched-source-id',
        'url' => 'http://primary-provider.test/live/pooluser/poolpass/123.ts',
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'username' => 'fallbackuser',
        'password' => $targetPlaylist->uuid,
    ]);

    // resolveInternalUrl() finds no matching channel via source_id, so this falls
    // through to the plain string swap - still internally consistent (stream ID
    // untouched), just not the direct-resolution shortcut.
    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe(rtrim(url('/'), '/').'/live/fallbackuser/'.$targetPlaylist->uuid.'/123.ts');
});

test('it resolves via channel_profile_map when source_id lookup fails', function () {
    $poolPlaylist = Playlist::factory()->create([
        'xtream_config' => [
            'url' => 'http://primary-provider.test',
            'username' => 'pooluser',
            'password' => 'poolpass',
        ],
    ]);

    $targetPlaylist = Playlist::factory()->create();

    // Source channel has a numeric source_id that doesn't match any channel on target
    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'source_id' => '1918533',
        'name' => 'TSN',
    ]);

    // Target channel has a different ID but same name
    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'source_id' => 'some-hash-id',
        'name' => 'TSN',
        'enabled' => true,
        'url' => 'http://real-upstream.test/live/user/pass/999.ts',
    ]);

    // Create the mapping
    ChannelProfileMap::create([
        'source_channel_id' => $sourceChannel->id,
        'target_playlist_id' => $targetPlaylist->id,
        'target_channel_id' => $targetChannel->id,
    ]);

    $profile = PlaylistProfile::factory()->create([
        'playlist_id' => $poolPlaylist->id,
        'url' => url('/'),
        'password' => $targetPlaylist->uuid,
    ]);

    // resolveInternalUrl() finds no matching channel via source_id (hash mismatch),
    // but the mapping table resolves it to the correct target channel.
    expect($profile->transformChannelUrl($sourceChannel))
        ->toBe($targetChannel->url);
});
