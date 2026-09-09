<?php

use App\Models\Channel;
use App\Models\ChannelProfileMap;
use App\Models\Group;
use App\Models\Playlist;

test('ChannelProfileMap resolves target channel for source channel', function () {
    $sourcePlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'name' => 'HBO',
    ]);

    $targetChannel = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'HBO',
    ]);

    ChannelProfileMap::create([
        'source_channel_id' => $sourceChannel->id,
        'target_playlist_id' => $targetPlaylist->id,
        'target_channel_id' => $targetChannel->id,
    ]);

    $mapping = ChannelProfileMap::resolve($sourceChannel->id, $targetPlaylist->id);

    expect($mapping)->not->toBeNull();
    expect($mapping->target_channel_id)->toBe($targetChannel->id);
});

test('ChannelProfileMap returns null when no mapping exists', function () {
    $sourcePlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceChannel = Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
    ]);

    $mapping = ChannelProfileMap::resolve($sourceChannel->id, $targetPlaylist->id);

    expect($mapping)->toBeNull();
});

test('ChannelProfileMap populateForPlaylist maps by exact name', function () {
    $sourcePlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'name' => 'HBO',
        'enabled' => true,
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'HBO',
        'enabled' => true,
    ]);

    $count = ChannelProfileMap::populateForPlaylist($sourcePlaylist, $targetPlaylist);

    expect($count)->toBe(1);
    expect(ChannelProfileMap::count())->toBe(1);
});

test('ChannelProfileMap populateForPlaylist skips ambiguous names with different groups', function () {
    $sourcePlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceGroup = Group::factory()->create(['playlist_id' => $sourcePlaylist->id, 'name' => 'USA']);
    $targetGroup1 = Group::factory()->create(['playlist_id' => $targetPlaylist->id, 'name' => 'USA']);
    $targetGroup2 = Group::factory()->create(['playlist_id' => $targetPlaylist->id, 'name' => 'Canada']);

    Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'name' => 'AMC',
        'group_id' => $sourceGroup->id,
        'enabled' => true,
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'AMC',
        'group_id' => $targetGroup1->id,
        'enabled' => true,
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'AMC',
        'group_id' => $targetGroup2->id,
        'enabled' => true,
    ]);

    // Two candidates with same name but different groups — ambiguous, should skip
    $count = ChannelProfileMap::populateForPlaylist($sourcePlaylist, $targetPlaylist);

    expect($count)->toBe(0);
});

test('ChannelProfileMap populateForPlaylist disambiguates by group', function () {
    $sourcePlaylist = Playlist::factory()->create();
    $targetPlaylist = Playlist::factory()->create();

    $sourceGroup = Group::factory()->create(['playlist_id' => $sourcePlaylist->id, 'name' => 'USA']);
    $targetGroup1 = Group::factory()->create(['playlist_id' => $targetPlaylist->id, 'name' => 'USA']);
    $targetGroup2 = Group::factory()->create(['playlist_id' => $targetPlaylist->id, 'name' => 'Canada']);

    Channel::factory()->create([
        'playlist_id' => $sourcePlaylist->id,
        'name' => 'AMC',
        'group_id' => $sourceGroup->id,
        'enabled' => true,
    ]);

    $target1 = Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'AMC',
        'group_id' => $targetGroup1->id,
        'enabled' => true,
    ]);

    Channel::factory()->create([
        'playlist_id' => $targetPlaylist->id,
        'name' => 'AMC',
        'group_id' => $targetGroup2->id,
        'enabled' => true,
    ]);

    // Source group "USA" matches target1 group "USA" — should map
    $count = ChannelProfileMap::populateForPlaylist($sourcePlaylist, $targetPlaylist);

    expect($count)->toBe(1);
    $mapping = ChannelProfileMap::first();
    expect($mapping->target_channel_id)->toBe($target1->id);
});
