<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources;

use Filament\Actions\Action as TableAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\ReservationResource\Pages\ListReservations;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class ReservationResource extends Resource
{
    protected static ?string $model = ResourceReservation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Reservation';

    protected static ?string $pluralModelLabel = 'Reservations';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->placeholder('Guest'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'confirmed' => 'success',
                        'expired' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('resources')
                    ->label('Resources')
                    ->getStateUsing(fn ($record) => sprintf(
                        '%sGB | %s cores | %sGB',
                        round($record->memory / 1024, 1),
                        round($record->cpu / 100, 1),
                        round($record->disk / 1024, 1)
                    )),
                TextColumn::make('calculated_price')
                    ->label('Price')
                    ->money('usd'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->color(fn ($record) => $record->status === 'pending' && $record->expires_at < now()->addMinutes(5)
                        ? 'danger'
                        : null
                    ),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                TableAction::make('extend')
                    ->label('Extend')
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->form([
                        TextInput::make('minutes')
                            ->label('Minutes to add')
                            ->numeric()
                            ->default(15)
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $actor = auth()->user();
                        abort_if($actor === null, 401);

                        app(ReservationService::class)->extend($record->token, (int) $data['minutes'], $actor);
                    }),
                TableAction::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $actor = auth()->user();
                        abort_if($actor === null, 401);

                        app(ReservationService::class)->cancel($record->token, 'Admin cancelled', 'admin', $actor);
                    }),
            ])
            ->headerActions([
                TableAction::make('cleanup')
                    ->label('Cleanup Expired')
                    ->icon('heroicon-o-trash')
                    ->action(fn () => app(ReservationService::class)->cleanupExpired())
                    ->requiresConfirmation()
                    ->modalDescription('This will mark all expired pending reservations as expired.'),
            ])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReservations::route('/'),
        ];
    }
}
