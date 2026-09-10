<?php

namespace App\Filament\Resources\EpisodeKeywordRules;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\EpisodeKeywordRules\Pages\CreateEpisodeKeywordRule;
use App\Filament\Resources\EpisodeKeywordRules\Pages\EditEpisodeKeywordRule;
use App\Filament\Resources\EpisodeKeywordRules\Pages\ListEpisodeKeywordRules;
use App\Models\EpisodeKeywordRule;
use App\Models\Playlist;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class EpisodeKeywordRuleResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = EpisodeKeywordRule::class;

    protected static ?string $recordTitleAttribute = 'expression';

    public static function getNavigationGroup(): ?string
    {
        return __('EPG');
    }

    public static function getModelLabel(): string
    {
        return __('Episode Keyword Rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Episode Keyword Rules');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseEpisodeKeywordRules();
    }

    public static function getNavigationSort(): ?int
    {
        return 50;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expression')
                    ->label(__('Expression'))
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->placeholder(__('All playlists'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->hiddenLabel(),
                DeleteAction::make()
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEpisodeKeywordRules::route('/'),
            'create' => CreateEpisodeKeywordRule::route('/create'),
            'edit' => EditEpisodeKeywordRule::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Section::make(__('Why use this?'))
                ->icon('heroicon-s-information-circle')
                ->description(__('Live sports are listed in the guide without episode numbers, so DVR apps like NextPVR treat every channel, next-day, and replay airing of a game as a separate programme and record them all. Programmes matching this rule get a stable, per-game episode ID in the generated EPG, letting the DVR collapse all airings of the same game into one recording.'))
                ->columnSpanFull(),
            Section::make(__('Match Rule'))
                ->icon('heroicon-s-tag')
                ->compact()
                ->columnSpanFull()
                ->schema([
                    TextInput::make('expression')
                        ->label(__('Expression'))
                        ->required()
                        ->maxLength(500)
                        ->helperText(__('NextPVR-style rule. Matched programmes get a stable, per-game episode number so DVRs can avoid duplicate recordings. Examples: `title like \'%CFL Football%\'`, `title like \'%CFL Football%\' and description like \'%at%\'`, or a bare keyword like `CFL`.'))
                        ->columnSpanFull(),
                    Grid::make(2)
                        ->columnSpanFull()
                        ->schema([
                            Select::make('playlist_id')
                                ->label(__('Playlist'))
                                ->placeholder(__('All playlists'))
                                ->helperText(__('Only apply this rule to the selected playlist. Leave empty to apply to all of your playlists.'))
                                ->options(Playlist::where('user_id', Auth::id())->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                            Toggle::make('enabled')
                                ->label(__('Enabled'))
                                ->default(true)
                                ->inline(false)
                                ->helperText(__('Disable a rule without deleting it. Disabled rules do not affect generated EPG data.')),
                        ]),
                ]),
        ];
    }
}
