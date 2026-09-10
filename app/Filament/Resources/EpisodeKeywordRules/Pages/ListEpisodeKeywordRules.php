<?php

namespace App\Filament\Resources\EpisodeKeywordRules\Pages;

use App\Filament\Resources\EpisodeKeywordRules\EpisodeKeywordRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListEpisodeKeywordRules extends ListRecords
{
    protected static string $resource = EpisodeKeywordRuleResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Stops DVR apps like NextPVR from recording the same live sporting event multiple times. Live sports are usually listed in the guide without episode numbers, so every channel, next-day, and replay airing looks like a unique programme. Add a rule to match those programmes (e.g. `title like \'%CFL Football%\'`) and each game gets one stable episode ID — all its airings then collapse into a single recording.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
