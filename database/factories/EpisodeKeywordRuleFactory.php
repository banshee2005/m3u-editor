<?php

namespace Database\Factories;

use App\Models\EpisodeKeywordRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EpisodeKeywordRuleFactory extends Factory
{
    protected $model = EpisodeKeywordRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'playlist_id' => null,
            'expression' => "title like '%CFL Football%'",
            'enabled' => true,
        ];
    }
}
