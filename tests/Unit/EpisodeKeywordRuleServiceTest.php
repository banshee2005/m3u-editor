<?php

use App\Models\EpisodeKeywordRule;
use App\Services\EpisodeKeywordRuleService;
use Illuminate\Support\Collection;

function ekrRule(string $expression, bool $enabled = true): EpisodeKeywordRule
{
    return EpisodeKeywordRule::factory()->make([
        'expression' => $expression,
        'enabled' => $enabled,
    ]);
}

function ekrService(EpisodeKeywordRule ...$rules): EpisodeKeywordRuleService
{
    return new EpisodeKeywordRuleService(new Collection($rules));
}

function ekrProgramme(string $title, string $desc = 'The BC Lions host the Saskatchewan Roughriders at BC Place. (Football) - 2026-08-23'): array
{
    return ['title' => $title, 'desc' => $desc];
}

it('strips unicode LIVE and NEW markers when normalizing a title', function () {
    $base = 'CFL Football - Saskatchewan Roughriders at BC Lions';

    expect(EpisodeKeywordRuleService::normalizeTitle($base.' ᴸᶦᵛᵉ'))->toBe(mb_strtolower($base))
        ->and(EpisodeKeywordRuleService::normalizeTitle($base.' ᴺᵉʷ'))->toBe(mb_strtolower($base))
        ->and(EpisodeKeywordRuleService::normalizeTitle($base))->toBe(mb_strtolower($base));
});

it('strips plain ASCII live/new suffixes when normalizing a title', function () {
    $base = 'CFL Football - Saskatchewan Roughriders at BC Lions';

    expect(EpisodeKeywordRuleService::normalizeTitle($base.' LIVE'))->toBe(mb_strtolower($base))
        ->and(EpisodeKeywordRuleService::normalizeTitle($base.' - NEW'))->toBe(mb_strtolower($base));
});

it('strips broadcast markers while preserving casing for the display title', function () {
    $base = 'CFL Football - Saskatchewan Roughriders at BC Lions';

    expect(EpisodeKeywordRuleService::stripBroadcastMarkers($base.' ᴸᶦᵛᵉ'))->toBe($base)
        ->and(EpisodeKeywordRuleService::stripBroadcastMarkers($base.' - NEW'))->toBe($base)
        ->and(EpisodeKeywordRuleService::stripBroadcastMarkers($base))->toBe($base);
});

it('extracts the trailing game date from a description', function () {
    expect(EpisodeKeywordRuleService::extractGameDate('The Lions host the Riders. (Football) - 2026-08-23'))
        ->toBe('2026-08-23')
        ->and(EpisodeKeywordRuleService::extractGameDate('No date here'))
        ->toBeNull();
});

it('builds identical identity keys for every airing of the same game', function () {
    $desc = 'The BC Lions host the Saskatchewan Roughriders at BC Place. (Football) - 2026-08-23';

    $live = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - Saskatchewan Roughriders at BC Lions ᴸᶦᵛᵉ', $desc, '2026-08-23'
    );
    $nextDay = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - Saskatchewan Roughriders at BC Lions ᴸᶦᵛᵉ', $desc, '2026-08-23'
    );
    $replay = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - Saskatchewan Roughriders at BC Lions ᴺᵉʷ', $desc, '2026-08-23'
    );

    expect($live)->toBe($nextDay)
        ->and($live)->toBe($replay);
});

it('builds distinct identity keys for different games even when the title is identical', function () {
    $week1Desc = 'The Riders host the Lions. (Football) - 2026-07-11';
    $week6Desc = 'The Riders host the Lions again. (Football) - 2026-08-15';

    $week1 = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - BC Lions at Saskatchewan Roughriders', $week1Desc, '2026-07-11'
    );
    $week6 = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - BC Lions at Saskatchewan Roughriders', $week6Desc, '2026-08-15'
    );

    expect($week1)->not->toBe($week6);
});

it('builds distinct identity keys for different matchups', function () {
    $gameA = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - BC Lions at Saskatchewan Roughriders', 'desc', '2026-08-23'
    );
    $gameB = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - Toronto Argonauts at Calgary Stampeders', 'desc', '2026-08-23'
    );

    expect($gameA)->not->toBe($gameB);
});

it('uses the description as a disambiguator when no game date is present', function () {
    $rematchA = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - BC Lions at Saskatchewan Roughriders', 'A rematch at Mosaic. (Football)', null
    );
    $rematchB = EpisodeKeywordRuleService::buildIdentityKey(
        'CFL Football - BC Lions at Saskatchewan Roughriders', 'The rubber match at Mosaic. (Football)', null
    );

    expect($rematchA)->not->toBe($rematchB);
});

it('produces a unique episode value per game and a stable one per game across airings', function () {
    $desc = '(Football) - 2026-08-23';

    $lions = 'CFL Football - Saskatchewan Roughriders at BC Lions';
    $riders = 'CFL Football - BC Lions at Saskatchewan Roughriders';

    $lionsLive = EpisodeKeywordRuleService::episodeValue(
        EpisodeKeywordRuleService::buildIdentityKey($lions, $desc, '2026-08-23'), '2026-08-23'
    );
    $lionsReplay = EpisodeKeywordRuleService::episodeValue(
        EpisodeKeywordRuleService::buildIdentityKey($lions.' ᴺᵉʷ', $desc, '2026-08-23'), '2026-08-23'
    );
    $ridersGame = EpisodeKeywordRuleService::episodeValue(
        EpisodeKeywordRuleService::buildIdentityKey($riders, $desc, '2026-08-23'), '2026-08-23'
    );

    expect($lionsLive)->toBe($lionsReplay)
        ->and($lionsLive)->not->toBe($ridersGame);
});

it('returns null when no rule expression matches the programme title', function () {
    $service = ekrService(ekrRule("title like '%MLB%'"));

    expect($service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions')))
        ->toBeNull();
});

it('synthesizes an xmltv_ns episode number for a matching programme', function () {
    $service = ekrService(ekrRule("title like '%CFL Football%'"));

    $synthesized = $service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions'));

    expect($synthesized)->not->toBeNull()
        ->and($synthesized['episode_nums'])->toHaveCount(2)
        ->and($synthesized['episode_nums'][0]['system'])->toBe('xmltv_ns')
        ->and($synthesized['episode_nums'][1]['system'])->toBe('dd_progid')
        ->and($synthesized['date'])->toBe('2026-08-23')
        ->and($synthesized['episode_nums'][0]['value'])->toMatch('/^\d+\.\d+\.$/')
        ->and($synthesized['episode_nums'][1]['value'])->toMatch('/^EP\d+\.\d+$/');
});

it('evaluates full expressions against title and description', function () {
    $service = ekrService(ekrRule("title like '%CFL Football%' and description like '%at%'"));

    expect($service->synthesizeForProgramme(ekrProgramme(
        'CFL Football - Saskatchewan Roughriders at BC Lions',
        'The BC Lions host the Saskatchewan Roughriders. (Football) - 2026-08-23',
    )))->not->toBeNull()
        ->and($service->synthesizeForProgramme(ekrProgramme(
            'CFL Football - Saskatchewan Roughriders at BC Lions',
            'No word here. (Football) - 2026-08-23',
        )))->toBeNull();
});

it('matches a bare keyword rule', function () {
    $service = ekrService(ekrRule('CFL'));

    expect($service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions')))
        ->not->toBeNull()
        ->and($service->synthesizeForProgramme(ekrProgramme('Soccer Night')))
        ->toBeNull();
});

it('synthesizes a stable episode for the same game across live and replay listings', function () {
    $service = ekrService(ekrRule("title like '%CFL%'"));
    $desc = 'The BC Lions host the Saskatchewan Roughriders at BC Place. (Football) - 2026-08-23';

    $live = $service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions ᴸᶦᵛᵉ', $desc));
    $replay = $service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions ᴺᵉʷ', $desc));

    expect($live['episode_nums'][0]['value'])->toBe($replay['episode_nums'][0]['value'])
        ->and($live['title'])->toBe($replay['title'])
        ->and($live['title'])->toBe('CFL Football - Saskatchewan Roughriders at BC Lions');
});

it('synthesizes different episodes for different games', function () {
    $service = ekrService(ekrRule("title like '%CFL%'"));

    $gameA = $service->synthesizeForProgramme(ekrProgramme(
        'CFL Football - Saskatchewan Roughriders at BC Lions',
        'The Riders visit the Lions. (Football) - 2026-08-23',
    ));
    $gameB = $service->synthesizeForProgramme(ekrProgramme(
        'CFL Football - Hamilton Tiger-Cats at Toronto Argonauts',
        'The Ticats visit the Argos. (Football) - 2026-08-22',
    ));

    expect($gameA['episode_nums'][0]['value'])->not->toBe($gameB['episode_nums'][0]['value']);
});

it('skips malformed expressions without throwing', function () {
    $service = ekrService(ekrRule("title like '%CFL%' junk here"));

    expect($service->synthesizeForProgramme(ekrProgramme('CFL Football - Saskatchewan Roughriders at BC Lions')))
        ->toBeNull();
});
