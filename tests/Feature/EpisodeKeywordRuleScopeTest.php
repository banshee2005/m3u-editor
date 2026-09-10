<?php

use App\Models\EpisodeKeywordRule;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpisodeKeywordRuleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ekrScopeUser(): User
{
    return Model::withoutEvents(fn () => User::factory()->create());
}

function ekrScopePlaylist(int $userId): Playlist
{
    return Model::withoutEvents(fn () => Playlist::factory()->create(['user_id' => $userId]));
}

function ekrScopeRule(int $userId, ?int $playlistId, string $expression): EpisodeKeywordRule
{
    return EpisodeKeywordRule::create([
        'user_id' => $userId,
        'playlist_id' => $playlistId,
        'expression' => $expression,
        'enabled' => true,
    ]);
}

it('loads playlist-bound and global rules for a playlist', function () {
    $user = ekrScopeUser();
    $playlist = ekrScopePlaylist($user->id);
    $otherPlaylist = ekrScopePlaylist($user->id);

    ekrScopeRule($user->id, $playlist->id, "title like '%A%'");
    ekrScopeRule($user->id, null, "title like '%B%'");
    ekrScopeRule($user->id, $otherPlaylist->id, "title like '%C%'");

    $service = EpisodeKeywordRuleService::forPlaylist($user->id, $playlist->id);

    expect($service->hasRules())->toBeTrue()
        ->and($service->synthesizeForProgramme(['title' => 'AAA']))->not->toBeNull()
        ->and($service->synthesizeForProgramme(['title' => 'BBB']))->not->toBeNull()
        ->and($service->synthesizeForProgramme(['title' => 'CCC']))->toBeNull();
});

it('only includes global rules when no playlist is in scope', function () {
    $user = ekrScopeUser();
    $playlist = ekrScopePlaylist($user->id);

    ekrScopeRule($user->id, $playlist->id, "title like '%A%'");
    ekrScopeRule($user->id, null, "title like '%B%'");

    $service = EpisodeKeywordRuleService::forPlaylist($user->id, null);

    expect($service->synthesizeForProgramme(['title' => 'AAA']))->toBeNull()
        ->and($service->synthesizeForProgramme(['title' => 'BBB']))->not->toBeNull();
});

it('orders playlist-bound rules ahead of global rules', function () {
    $user = ekrScopeUser();
    $playlist = ekrScopePlaylist($user->id);

    ekrScopeRule($user->id, null, "title like '%Global%'");
    ekrScopeRule($user->id, $playlist->id, "title like '%Local%'");

    $service = EpisodeKeywordRuleService::forPlaylist($user->id, $playlist->id);

    $rules = (new ReflectionClass($service))->getProperty('rules')->getValue($service);

    expect($rules)->toHaveCount(2)
        ->and($rules->first()->playlist_id)->toBe($playlist->id)
        ->and($rules->last()->playlist_id)->toBeNull();
});

it('excludes disabled rules', function () {
    $user = ekrScopeUser();

    EpisodeKeywordRule::create([
        'user_id' => $user->id,
        'playlist_id' => null,
        'expression' => "title like '%CFL%'",
        'enabled' => false,
    ]);

    $service = EpisodeKeywordRuleService::forPlaylist($user->id, null);

    expect($service->hasRules())->toBeFalse()
        ->and($service->synthesizeForProgramme(['title' => 'CFL Football']))->toBeNull();
});

it('does not leak other users rules', function () {
    $userA = ekrScopeUser();
    $userB = ekrScopeUser();

    ekrScopeRule($userA->id, null, "title like '%CFL%'");

    $service = EpisodeKeywordRuleService::forPlaylist($userB->id, null);

    expect($service->hasRules())->toBeFalse()
        ->and($service->synthesizeForProgramme(['title' => 'CFL Football']))->toBeNull();
});
