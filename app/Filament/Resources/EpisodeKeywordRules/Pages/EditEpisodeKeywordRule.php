<?php

namespace App\Filament\Resources\EpisodeKeywordRules\Pages;

use App\Filament\Resources\EpisodeKeywordRules\EpisodeKeywordRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEpisodeKeywordRule extends EditRecord
{
    protected static string $resource = EpisodeKeywordRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
