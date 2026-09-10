<?php

use App\Support\RuleExpressionParser;

it('matches a simple title like expression', function () {
    expect(RuleExpressionParser::matches(
        "title like '%CFL Football%'",
        ['title' => 'CFL Football - Saskatchewan Roughriders at BC Lions', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "title like '%CFL Football%'",
            ['title' => 'Football Focus', 'description' => ''],
        ))->toBeFalse();
});

it('is case-insensitive for fields and values', function () {
    expect(RuleExpressionParser::matches(
        'TITLE LIKE \'%cfl football%\'',
        ['title' => 'CFL Football - BC Lions at Saskatchewan Roughriders', 'description' => ''],
    ))->toBeTrue();
});

it('combines title and description conditions with and', function () {
    expect(RuleExpressionParser::matches(
        "title like '%CFL Football%' and description like '%at%'",
        ['title' => 'CFL Football - BC Lions at Saskatchewan Roughriders', 'description' => 'The Lions host the Riders at BC Place.'],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "title like '%CFL Football%' and description like '%at%'",
            ['title' => 'CFL Football - BC Lions at Saskatchewan Roughriders', 'description' => 'Nothing here to find.'],
        ))->toBeFalse();
});

it('supports or between conditions', function () {
    expect(RuleExpressionParser::matches(
        "title like '%CFL%' or title like '%NFL%'",
        ['title' => 'Live NFL Football', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "title like '%CFL%' or title like '%NFL%'",
            ['title' => 'Soccer', 'description' => ''],
        ))->toBeFalse();
});

it('supports parentheses for grouping', function () {
    expect(RuleExpressionParser::matches(
        "title like '%CFL%' and (description like '%at%' or subtitle like '%Week 12%')",
        ['title' => 'CFL Football', 'description' => 'nothing', 'subtitle' => 'Week 12'],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "title like '%CFL%' and (description like '%at%' or subtitle like '%Week 12%')",
            ['title' => 'CFL Football', 'description' => 'nothing', 'subtitle' => 'Nothing'],
        ))->toBeFalse();
});

it('matches exact equality case-insensitively', function () {
    expect(RuleExpressionParser::matches(
        "title = 'CFL Football'",
        ['title' => 'cfl football', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "title = 'CFL Football'",
            ['title' => 'CFL Football Extra', 'description' => ''],
        ))->toBeFalse();
});

it('supports not-equal operators', function () {
    expect(RuleExpressionParser::matches(
        "title != 'News'",
        ['title' => 'CFL Football', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "description <> 'Nothing'",
            ['title' => 'Anything', 'description' => 'Nothing'],
        ))->toBeFalse();
});

it('treats % as any-character wildcard and _ as single character', function () {
    expect(RuleExpressionParser::matches(
        "title like '%CFL%'",
        ['title' => 'CFL Football', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            'title like \'CFL_Football\'',
            ['title' => 'CFL Football', 'description' => ''],
        ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            'title like \'CFL_Football\'',
            ['title' => 'CFL XFootball', 'description' => ''],
        ))->toBeFalse();
});

it('matches against description, subtitle, and category fields', function () {
    expect(RuleExpressionParser::matches(
        "description like '%Week 12%'",
        ['title' => '', 'description' => 'A Week 12 matchup', 'subtitle' => '', 'category' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "subtitle like '%Week 12%'",
            ['title' => '', 'description' => '', 'subtitle' => 'Week 12: Showdown', 'category' => ''],
        ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            "category = 'Sports'",
            ['title' => '', 'description' => '', 'subtitle' => '', 'category' => 'sports'],
        ))->toBeTrue();
});

it('treats a bare keyword as a title contains check', function () {
    expect(RuleExpressionParser::matches(
        'CFL',
        ['title' => 'CFL Football - Saskatchewan Roughriders at BC Lions', 'description' => ''],
    ))->toBeTrue()
        ->and(RuleExpressionParser::matches(
            'CFL',
            ['title' => 'Soccer Night', 'description' => ''],
        ))->toBeFalse();
});

it('treats a multi-word bare keyword as a title contains check', function () {
    expect(RuleExpressionParser::matches(
        'CFL Football',
        ['title' => 'CFL Football - Saskatchewan Roughriders at BC Lions', 'description' => ''],
    ))->toBeTrue();
});

it('returns false for an empty expression', function () {
    expect(RuleExpressionParser::matches('', ['title' => 'CFL Football']))->toBeFalse();
});

it('throws on a malformed expression', function () {
    RuleExpressionParser::matches("title like '%CFL%' junk here", ['title' => 'CFL Football']);
})->throws(InvalidArgumentException::class);
