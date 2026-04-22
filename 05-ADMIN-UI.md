# Admin Interface

> **Related docs**: [02-SERVICES.md](02-SERVICES.md) (services used), [01-DATABASE.md](01-DATABASE.md) (models)

Built with [Filament](https://filamentphp.com/docs/4.x/) for a modern admin experience.

---

## Navigation Structure

```
Dynamic Pterodactyl (Admin Menu)
├── Dashboard            → System health, quick stats, capacity overview
├── Pricing Configs      → Visual pricing configuration per product
├── Node Monitoring      → Real-time capacity per location/node
├── Reservations         → Active/historical reservations, manual actions
├── Analytics            → Revenue, popular configs, trends
├── Alerts               → Capacity alert configuration
├── Settings             → Connection, defaults, display options
└── Audit Log            → Configuration change history
```

---

## Dashboard Page

**File**: `Filament/Pages/Dashboard.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Pages;

use Filament\Pages\Page;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'dynamic-pterodactyl::admin.dashboard';
    
    public function getViewData(): array
    {
        $resourceService = app(ResourceCalculationService::class);
        $reservationService = app(ReservationService::class);
        
        $connectionStatus = $resourceService->testConnection();
        $stats = $reservationService->getStatistics('30d');
        
        $locations = [];
        try {
            foreach ($resourceService->getLocations() as $location) {
                $availability = $resourceService->getLocationAvailability($location['id']);
                $locations[] = array_merge($location, [
                    'capacity' => $availability,
                    'health' => $this->calculateLocationHealth($availability),
                ]);
            }
        } catch (\Exception $e) {
            // Handle gracefully
        }
        
        $activeConfigs = \DB::table('config_options')
            ->where('type', 'dynamic_slider')->count();
        
        $pendingReservations = \DB::table('ptero_resource_reservations')
            ->where('status', 'pending')
            ->where('expires_at', '>', now())->count();
        
        return [
            'connectionStatus' => $connectionStatus,
            'stats' => $stats,
            'locations' => $locations,
            'activeConfigs' => $activeConfigs,
            'pendingReservations' => $pendingReservations,
        ];
    }
    
    private function calculateLocationHealth(array $availability): string
    {
        $memoryUtil = $availability['total_capacity']['memory'] > 0
            ? ($availability['total_allocated']['memory'] / $availability['total_capacity']['memory']) * 100
            : 100;
        
        if ($memoryUtil >= 95) return 'critical';
        if ($memoryUtil >= 80) return 'warning';
        return 'healthy';
    }
}
```

### Dashboard View

**File**: `resources/views/admin/dashboard.blade.php`

```blade
<x-filament-panels::page>
    {{-- Connection Status Banner --}}
    @if(!$connectionStatus['success'])
        <div class="p-4 mb-6 bg-danger-100 border border-danger-300 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-exclamation-circle class="w-6 h-6 text-danger-600 mr-3" />
                <div>
                    <h3 class="font-semibold text-danger-800">Pterodactyl Connection Failed</h3>
                    <p class="text-danger-700">{{ $connectionStatus['message'] }}</p>
                </div>
            </div>
        </div>
    @else
        <div class="p-4 mb-6 bg-success-100 border border-success-300 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 mr-3" />
                <span class="font-semibold text-success-800">Connected</span>
                <span class="text-success-700 ml-2">{{ $connectionStatus['node_count'] }} nodes</span>
            </div>
        </div>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-filament::card>
            <div class="text-sm text-gray-500">Active Pricing Configs</div>
            <div class="text-3xl font-bold">{{ $activeConfigs }}</div>
        </x-filament::card>
        
        <x-filament::card>
            <div class="text-sm text-gray-500">Pending Reservations</div>
            <div class="text-3xl font-bold">{{ $pendingReservations }}</div>
        </x-filament::card>
        
        <x-filament::card>
            <div class="text-sm text-gray-500">30-Day Revenue</div>
            <div class="text-3xl font-bold">${{ number_format($stats['confirmed_revenue'], 2) }}</div>
        </x-filament::card>
        
        <x-filament::card>
            <div class="text-sm text-gray-500">Conversion Rate</div>
            <div class="text-3xl font-bold">{{ $stats['conversion_rate'] }}%</div>
        </x-filament::card>
    </div>

    {{-- Location Capacity --}}
    <x-filament::card class="mb-6">
        <h2 class="text-lg font-semibold mb-4">Location Capacity</h2>
        <div class="space-y-4">
            @foreach($locations as $location)
                <div class="border rounded-lg p-4">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="font-medium">{{ $location['long'] }}</h3>
                        <span class="px-2 py-1 text-xs rounded-full 
                            {{ $location['health'] === 'healthy' ? 'bg-success-100 text-success-800' : '' }}
                            {{ $location['health'] === 'warning' ? 'bg-warning-100 text-warning-800' : '' }}
                            {{ $location['health'] === 'critical' ? 'bg-danger-100 text-danger-800' : '' }}">
                            {{ ucfirst($location['health']) }}
                        </span>
                    </div>
                    
                    @php
                        $memPercent = $location['capacity']['total_capacity']['memory'] > 0 
                            ? ($location['capacity']['total_allocated']['memory'] / $location['capacity']['total_capacity']['memory']) * 100 
                            : 0;
                    @endphp
                    
                    <div class="mb-2">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Memory</span>
                            <span>{{ number_format($location['capacity']['total_allocated']['memory'] / 1024, 1) }}GB / 
                                  {{ number_format($location['capacity']['total_capacity']['memory'] / 1024, 1) }}GB</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $memPercent >= 95 ? 'bg-danger-500' : ($memPercent >= 80 ? 'bg-warning-500' : 'bg-success-500') }}" 
                                 style="width: {{ min($memPercent, 100) }}%"></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::card>
</x-filament-panels::page>
```

---

## Setup Wizard

**File**: `Admin/Pages/SetupWizard.php`

Pricing and slider configuration is now managed in a single wizard that writes native Paymenter `ConfigOption` rows.
The wizard sets `type='dynamic_slider'` and stores slider metadata in `metadata.resource_type`.


## Reservation Resource

**File**: `Filament/Resources/ReservationResource.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\ResourceReservation;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ReservationService;

class ReservationResource extends Resource
{
    protected static ?string $model = ResourceReservation::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'confirmed',
                        'danger' => 'expired',
                        'secondary' => 'cancelled',
                    ]),
                Tables\Columns\TextColumn::make('resources')
                    ->getStateUsing(fn ($record) => sprintf(
                        '%sGB | %s cores | %sGB',
                        $record->memory / 1024,
                        $record->cpu / 100,
                        $record->disk / 1024
                    )),
                Tables\Columns\TextColumn::make('calculated_price')
                    ->money('usd'),
                Tables\Columns\TextColumn::make('expires_at')
                    ->dateTime()
                    ->color(fn ($record) => 
                        $record->status === 'pending' && $record->expires_at < now()->addMinutes(5)
                            ? 'danger' : null
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'expired' => 'Expired',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('extend')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('minutes')
                            ->numeric()
                            ->default(15),
                    ])
                    ->action(fn ($record, array $data) => 
                        app(ReservationService::class)->extend($record->token, $data['minutes'])
                    ),
                Tables\Actions\Action::make('cancel')
                    ->color('danger')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(fn ($record) => 
                        app(ReservationService::class)->cancel($record->token, 'Admin cancelled', true)
                    ),
            ])
            ->headerActions([
                Tables\Actions\Action::make('cleanup')
                    ->label('Cleanup Expired')
                    ->action(fn () => app(ReservationService::class)->cleanupExpired()),
            ])
            ->poll('30s');
    }
}
```

---

Settings are now handled by `Admin/Pages/SetupWizard.php`; the separate settings page is no longer used.


## Audit Log Page

**File**: `Filament/Pages/AuditLogPage.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Pages;

use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AuditLog;

class AuditLogPage extends Page implements HasTable
{
    use InteractsWithTable;
    
    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 10;
    protected static string $view = 'dynamic-pterodactyl::admin.audit-log';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_name')
                    ->searchable(),
                Tables\Columns\BadgeColumn::make('action')
                    ->colors([
                        'success' => 'created',
                        'warning' => 'updated',
                        'danger' => 'deleted',
                    ]),
                Tables\Columns\TextColumn::make('entity_type'),
                Tables\Columns\TextColumn::make('entity_name'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action'),
                Tables\Filters\SelectFilter::make('entity_type'),
            ]);
    }
}
```

The audit log table is read-only. Rows are not clickable, and there is no JSON diff modal in the current implementation.

---

## Alert Config Resource

**File**: `Filament/Resources/AlertConfigResource.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\AlertConfig;

class AlertConfigResource extends Resource
{
    protected static ?string $model = AlertConfig::class;
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('location_id')
                ->label('Location')
                ->options(fn () => array_merge(
                    [null => 'Global (All)'],
                    self::getLocationOptions()
                )),
            
            Forms\Components\Toggle::make('is_active')->default(true),
            
            Forms\Components\Section::make('Thresholds')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\TextInput::make('memory_warning_threshold')
                            ->suffix('%')
                            ->default(80),
                        Forms\Components\TextInput::make('memory_critical_threshold')
                            ->suffix('%')
                            ->default(95),
                        Forms\Components\TextInput::make('disk_warning_threshold')
                            ->suffix('%')
                            ->default(80),
                        Forms\Components\TextInput::make('disk_critical_threshold')
                            ->suffix('%')
                            ->default(95),
                    ]),
                ]),
            
            Forms\Components\Section::make('Notifications')
                ->schema([
                    Forms\Components\Toggle::make('email_notifications')->default(true)->live(),
                    Forms\Components\TagsInput::make('notification_emails')
                        ->visible(fn (Forms\Get $get) => $get('email_notifications')),
                    
                    Forms\Components\Toggle::make('webhook_notifications')->default(false)->live(),
                    Forms\Components\TextInput::make('webhook_url')
                        ->url()
                        ->visible(fn (Forms\Get $get) => $get('webhook_notifications')),
                    
                    Forms\Components\TextInput::make('cooldown_minutes')
                        ->suffix('minutes')
                        ->default(60),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('location_name')
                    ->default('Global'),
                Tables\Columns\TextColumn::make('thresholds')
                    ->getStateUsing(fn ($record) => "Mem: {$record->memory_warning_threshold}%/{$record->memory_critical_threshold}%"),
                Tables\Columns\IconColumn::make('email_notifications')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('last_notification_at')
                    ->dateTime()
                    ->placeholder('Never'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('test')
                    ->action(fn ($record) => app(AlertService::class)->sendTestNotification($record)),
            ]);
    }
}
```

---

## Node Monitoring Page

**File**: `Filament/Pages/NodeMonitoring.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Pages;

use Filament\Pages\Page;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class NodeMonitoring extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'dynamic-pterodactyl::admin.node-monitoring';
    
    public function getViewData(): array
    {
        $resourceService = app(ResourceCalculationService::class);
        $locations = [];
        
        try {
            foreach ($resourceService->getLocations() as $location) {
                $availability = $resourceService->getLocationAvailability($location['id']);
                $locations[] = [
                    'id' => $location['id'],
                    'name' => $location['long'],
                    'nodes' => $availability['nodes'],
                    'totals' => [
                        'capacity' => $availability['total_capacity'],
                        'allocated' => $availability['total_allocated'],
                    ],
                ];
            }
        } catch (\Exception $e) {
            // Handle gracefully
        }
        
        return ['locations' => $locations, 'lastUpdated' => now()];
    }
}
```
