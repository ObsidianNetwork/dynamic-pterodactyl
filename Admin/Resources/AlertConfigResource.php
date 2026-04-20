<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources;

use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Actions\Action as TableAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource\Pages\CreateAlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource\Pages\EditAlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource\Pages\ListAlertConfigs;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\AlertService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class AlertConfigResource extends Resource
{
    protected static ?string $model = AlertConfig::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Alert Config';

    protected static ?string $pluralModelLabel = 'Alert Configs';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')
                ->label('Location')
                ->options(fn () => array_merge(
                    ['' => 'Global (All Locations)'],
                    self::getLocationOptions()
                ))
                ->placeholder('Global (All Locations)'),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true),

            Section::make('Thresholds')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('memory_warning_threshold')
                            ->label('Memory Warning')
                            ->suffix('%')
                            ->numeric()
                            ->default(80)
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('memory_critical_threshold')
                            ->label('Memory Critical')
                            ->suffix('%')
                            ->numeric()
                            ->default(95)
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('disk_warning_threshold')
                            ->label('Disk Warning')
                            ->suffix('%')
                            ->numeric()
                            ->default(80)
                            ->minValue(1)
                            ->maxValue(100),
                        TextInput::make('disk_critical_threshold')
                            ->label('Disk Critical')
                            ->suffix('%')
                            ->numeric()
                            ->default(95)
                            ->minValue(1)
                            ->maxValue(100),
                    ]),
                ]),

            Section::make('Notifications')
                ->schema([
                    Toggle::make('email_notifications')
                        ->label('Email Notifications')
                        ->default(true)
                        ->live(),

                    TagsInput::make('notification_emails')
                        ->label('Notification Email Addresses')
                        ->visible(fn (Get $get) => $get('email_notifications'))
                        ->placeholder('Add email address'),

                    Toggle::make('webhook_notifications')
                        ->label('Webhook Notifications')
                        ->default(false)
                        ->live(),

                    TextInput::make('webhook_url')
                        ->label('Webhook URL')
                        ->url()
                        ->visible(fn (Get $get) => $get('webhook_notifications'))
                        ->placeholder('https://...'),

                    TextInput::make('cooldown_minutes')
                        ->label('Notification Cooldown')
                        ->suffix('minutes')
                        ->numeric()
                        ->default(60)
                        ->helperText('Minimum time between repeated alerts'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('location_name')
                    ->label('Location')
                    ->getStateUsing(fn ($record) => $record->location_id
                        ? self::getLocationName($record->location_id)
                        : 'Global'
                    ),
                TextColumn::make('thresholds')
                    ->label('Thresholds')
                    ->getStateUsing(fn ($record) => sprintf(
                        'Mem: %d%%/%d%% | Disk: %d%%/%d%%',
                        $record->memory_warning_threshold,
                        $record->memory_critical_threshold,
                        $record->disk_warning_threshold,
                        $record->disk_critical_threshold
                    )),
                IconColumn::make('email_notifications')
                    ->label('Email')
                    ->boolean(),
                IconColumn::make('webhook_notifications')
                    ->label('Webhook')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_notification_at')
                    ->label('Last Alert')
                    ->dateTime()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                EditAction::make(),
                TableAction::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->action(fn ($record) => app(AlertService::class)->sendTestNotification($record))
                    ->requiresConfirmation()
                    ->modalDescription('This will send a test notification using this alert config.'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAlertConfigs::route('/'),
            'create' => CreateAlertConfig::route('/create'),
            'edit' => EditAlertConfig::route('/{record}/edit'),
        ];
    }

    private static function getLocationOptions(): array
    {
        try {
            $locations = app(ResourceCalculationService::class)->getLocations();

            return collect($locations)->pluck('long', 'id')->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function getLocationName(int $locationId): string
    {
        $locations = self::getLocationOptions();

        return $locations[$locationId] ?? "Location #{$locationId}";
    }
}
