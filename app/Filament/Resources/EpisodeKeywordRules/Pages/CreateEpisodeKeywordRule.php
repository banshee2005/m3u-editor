<?php

namespace App\Filament\Resources\EpisodeKeywordRules\Pages;

use App\Filament\Resources\EpisodeKeywordRules\EpisodeKeywordRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateEpisodeKeywordRule extends CreateRecord
{
    protected static string $resource = EpisodeKeywordRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
