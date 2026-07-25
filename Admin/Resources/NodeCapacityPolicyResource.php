<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages\CreateNodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages\EditNodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages\ListNodeCapacityPolicies;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\NodeCapacityPolicy;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\PterodactylInventoryService;

class NodeCapacityPolicyResource extends Resource
{
    protected static ?string $model = NodeCapacityPolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Node CPU Policy';

    protected static ?string $pluralModelLabel = 'Node CPU Policies';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Authoritative CPU Stock')
                ->description(
                    'CPU is measured in Pterodactyl percent: 100% equals one logical core. '
                    .'Enabling a policy dedicates that node to reservation-backed products; '
                    .'Paymenter static provisioning and non-capacity upgrades are blocked there. '
                    .'Nodes without an enabled policy are excluded from dynamic stock.'
                )
                ->schema([
                    Select::make('node_uuid')
                        ->label('Pterodactyl Node')
                        ->options(fn (): array => self::nodeOptions())
                        ->searchable()
                        ->required(),
                    TextInput::make('cpu_capacity_percent')
                        ->label('Physical CPU Capacity')
                        ->suffix('%')
                        ->helperText('For example, an 8-thread node has 800% physical capacity.')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(NodeCapacityPolicy::MAX_CPU_CAPACITY_PERCENT)
                        ->required(),
                    TextInput::make('cpu_overcommit_bps')
                        ->label('CPU Overcommit Ratio')
                        ->suffix('basis points')
                        ->helperText('10,000 = 1.0×, 15,000 = 1.5×, 20,000 = 2.0×.')
                        ->numeric()
                        ->integer()
                        ->default(10000)
                        ->minValue(1)
                        ->maxValue(NodeCapacityPolicy::MAX_CPU_OVERCOMMIT_BPS)
                        ->required(),
                    Toggle::make('enabled')
                        ->label('Dedicate this node to dynamic stock')
                        ->helperText(
                            'Enabled nodes must not receive servers or resource changes outside the reservation-backed flow.'
                        )
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('node_id')->label('Node ID')->sortable(),
                TextColumn::make('node_uuid')->label('Node UUID')->copyable(),
                TextColumn::make('cpu_capacity_percent')
                    ->label('Physical CPU')
                    ->suffix('%')
                    ->numeric(),
                TextColumn::make('cpu_overcommit_bps')
                    ->label('Overcommit')
                    ->formatStateUsing(
                        fn (int $state): string => number_format($state / 10000, 2).'×'
                    ),
                TextColumn::make('effective_cpu')
                    ->label('Effective CPU')
                    ->getStateUsing(
                        fn (NodeCapacityPolicy $record): string =>
                            number_format($record->effectiveCpuCapacity()).'%'
                    ),
                IconColumn::make('enabled')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNodeCapacityPolicies::route('/'),
            'create' => CreateNodeCapacityPolicy::route('/create'),
            'edit' => EditNodeCapacityPolicy::route('/{record}/edit'),
        ];
    }

    /**
     * Resolve panel and numeric node identity from the live Pterodactyl
     * inventory instead of accepting either value from an administrator form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withInventoryIdentity(array $data): array
    {
        $inventory = app(PterodactylInventoryService::class);
        $node = collect($inventory->nodes())->firstWhere(
            'uuid',
            (string) ($data['node_uuid'] ?? '')
        );

        if (! is_array($node)) {
            throw ValidationException::withMessages([
                'node_uuid' => 'The selected node is no longer present in Pterodactyl.',
            ]);
        }

        $data['node_id'] = (int) $node['id'];
        $data['location_id'] = (int) $node['location_id'];
        $data['panel_identity'] = $inventory->panelIdentity();

        return $data;
    }

    private static function nodeOptions(): array
    {
        try {
            return collect(app(PterodactylInventoryService::class)->nodes())
                ->mapWithKeys(fn (array $node): array => [
                    $node['uuid'] => sprintf('%s (#%d)', $node['name'], $node['id']),
                ])
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
