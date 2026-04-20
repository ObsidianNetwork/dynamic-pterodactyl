<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Pages;

use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AuditLog;

class AuditLogPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'dynamic-pterodactyl/audit-log';

    protected static ?string $title = 'Audit Log';

    protected string $view = 'dynamic-pterodactyl::admin.audit-log';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query())
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('user_name')
                    ->label('User')
                    ->searchable()
                    ->placeholder('System'),
                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'cancelled' => 'gray',
                        default => 'primary',
                    }),
                TextColumn::make('entity_type')
                    ->label('Type'),
                TextColumn::make('entity_name')
                    ->label('Entity')
                    ->limit(30),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('entity_type')
                    ->options([
                        'pricing_config' => 'Pricing Config',
                        'alert_config' => 'Alert Config',
                        'reservation' => 'Reservation',
                    ]),
            ])
            ->recordAction(null)
            ->recordUrl(null);
    }
}
