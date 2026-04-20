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
        
        $activeConfigs = \DB::table('ptero_pricing_configs')
            ->where('is_active', true)->count();
        
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

## Pricing Config Resource

**File**: `Filament/Resources/PricingConfigResource.php`

Visual form for configuring pricing without JSON editing.

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Paymenter\Extensions\Others\DynamicPterodactyl\Models\PricingConfig;

class PricingConfigResource extends Resource
{
    protected static ?string $model = PricingConfig::class;
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Config')->tabs([
                
                // TAB 1: Basic Settings
                Forms\Components\Tabs\Tab::make('Basic')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name')
                            ->required()
                            ->unique(ignoreRecord: true),
                        
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        
                        Forms\Components\Select::make('pricing_model')
                            ->options([
                                'linear' => 'Linear (per-unit)',
                                'tiered' => 'Tiered (volume discounts)',
                                'base_plus_addon' => 'Base + Addon',
                            ])
                            ->default('linear')
                            ->live()
                            ->required(),
                        
                        Forms\Components\TextInput::make('base_price')
                            ->prefix('$')
                            ->numeric()
                            ->default(0),
                    ]),
                
                // TAB 2: Pricing Details
                Forms\Components\Tabs\Tab::make('Pricing')
                    ->schema([
                        // LINEAR
                        Forms\Components\Section::make('Linear Rates')
                            ->visible(fn (Get $get) => $get('pricing_model') === 'linear')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('linear_memory_rate')
                                        ->label('Memory')
                                        ->prefix('$')
                                        ->suffix('/GB')
                                        ->numeric()
                                        ->default(0.50),
                                    Forms\Components\TextInput::make('linear_cpu_rate')
                                        ->label('CPU')
                                        ->prefix('$')
                                        ->suffix('/core')
                                        ->numeric()
                                        ->default(2.00),
                                    Forms\Components\TextInput::make('linear_disk_rate')
                                        ->label('Disk')
                                        ->prefix('$')
                                        ->suffix('/GB')
                                        ->numeric()
                                        ->default(0.02),
                                ]),
                            ]),
                        
                        // TIERED
                        Forms\Components\Section::make('Memory Tiers')
                            ->visible(fn (Get $get) => $get('pricing_model') === 'tiered')
                            ->schema([
                                Forms\Components\Repeater::make('memory_tiers')
                                    ->schema([
                                        Forms\Components\TextInput::make('up_to_gb')
                                            ->label('Up to (GB)')
                                            ->numeric()
                                            ->placeholder('∞'),
                                        Forms\Components\TextInput::make('per_gb')
                                            ->label('$/GB')
                                            ->numeric()
                                            ->required(),
                                    ])
                                    ->columns(2)
                                    ->addActionLabel('Add Tier'),
                            ]),
                        
                        // BASE + ADDON
                        Forms\Components\Section::make('Included Resources')
                            ->visible(fn (Get $get) => $get('pricing_model') === 'base_plus_addon')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('included_memory')
                                        ->suffix('GB')
                                        ->numeric()
                                        ->default(4),
                                    Forms\Components\TextInput::make('included_cpu')
                                        ->suffix('cores')
                                        ->numeric()
                                        ->default(2),
                                    Forms\Components\TextInput::make('included_disk')
                                        ->suffix('GB')
                                        ->numeric()
                                        ->default(50),
                                ]),
                            ]),
                        
                        Forms\Components\Section::make('Overage Rates')
                            ->visible(fn (Get $get) => $get('pricing_model') === 'base_plus_addon')
                            ->schema([
                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\TextInput::make('overage_memory')
                                        ->prefix('$')
                                        ->suffix('/GB')
                                        ->numeric()
                                        ->default(0.75),
                                    Forms\Components\TextInput::make('overage_cpu')
                                        ->prefix('$')
                                        ->suffix('/core')
                                        ->numeric()
                                        ->default(3.00),
                                    Forms\Components\TextInput::make('overage_disk')
                                        ->prefix('$')
                                        ->suffix('/GB')
                                        ->numeric()
                                        ->default(0.05),
                                ]),
                            ]),
                    ]),
                
                // TAB 3: Slider Limits
                Forms\Components\Tabs\Tab::make('Sliders')
                    ->schema([
                        Forms\Components\Section::make('Memory Slider')
                            ->schema([
                                Forms\Components\Toggle::make('enable_memory_slider')->default(true)->live(),
                                Forms\Components\Grid::make(4)
                                    ->visible(fn (Get $get) => $get('enable_memory_slider'))
                                    ->schema([
                                        Forms\Components\TextInput::make('min_memory_gb')
                                            ->label('Min')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(1),
                                        Forms\Components\TextInput::make('max_memory_gb')
                                            ->label('Max')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(64),
                                        Forms\Components\TextInput::make('memory_step_gb')
                                            ->label('Step')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(1),
                                        Forms\Components\TextInput::make('default_memory_gb')
                                            ->label('Default')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(4),
                                    ]),
                            ]),
                        
                        Forms\Components\Section::make('CPU Slider')
                            ->schema([
                                Forms\Components\Toggle::make('enable_cpu_slider')->default(true)->live(),
                                Forms\Components\Grid::make(4)
                                    ->visible(fn (Get $get) => $get('enable_cpu_slider'))
                                    ->schema([
                                        Forms\Components\TextInput::make('min_cpu_cores')
                                            ->label('Min')
                                            ->suffix('cores')
                                            ->numeric()
                                            ->default(1),
                                        Forms\Components\TextInput::make('max_cpu_cores')
                                            ->label('Max')
                                            ->suffix('cores')
                                            ->numeric()
                                            ->default(8),
                                        Forms\Components\TextInput::make('cpu_step_cores')
                                            ->label('Step')
                                            ->numeric()
                                            ->default(1),
                                        Forms\Components\TextInput::make('default_cpu_cores')
                                            ->label('Default')
                                            ->suffix('cores')
                                            ->numeric()
                                            ->default(2),
                                    ]),
                            ]),
                        
                        Forms\Components\Section::make('Disk Slider')
                            ->schema([
                                Forms\Components\Toggle::make('enable_disk_slider')->default(true)->live(),
                                Forms\Components\Grid::make(4)
                                    ->visible(fn (Get $get) => $get('enable_disk_slider'))
                                    ->schema([
                                        Forms\Components\TextInput::make('min_disk_gb')
                                            ->label('Min')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(10),
                                        Forms\Components\TextInput::make('max_disk_gb')
                                            ->label('Max')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(500),
                                        Forms\Components\TextInput::make('disk_step_gb')
                                            ->label('Step')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(10),
                                        Forms\Components\TextInput::make('default_disk_gb')
                                            ->label('Default')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(50),
                                    ]),
                            ]),
                    ]),
                
                // TAB 4: Display Settings
                Forms\Components\Tabs\Tab::make('Display')
                    ->schema([
                        Forms\Components\Grid::make(2)->schema([
                            Forms\Components\TextInput::make('display_memory_label')
                                ->default('RAM'),
                            Forms\Components\TextInput::make('display_cpu_label')
                                ->default('CPU Cores'),
                            Forms\Components\TextInput::make('display_disk_label')
                                ->default('Storage'),
                            Forms\Components\Select::make('display_price_format')
                                ->options([
                                    'monthly' => 'Monthly',
                                    'hourly' => 'Hourly',
                                    'both' => 'Both',
                                ])
                                ->default('monthly'),
                        ]),
                        
                        Forms\Components\Toggle::make('show_price_breakdown')
                            ->default(true),
                        
                        Forms\Components\Select::make('allowed_locations')
                            ->multiple()
                            ->options(fn () => self::getLocationOptions())
                            ->helperText('Empty = all locations'),
                    ]),
                
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('pricing_model')
                    ->colors([
                        'primary' => 'linear',
                        'success' => 'tiered',
                        'warning' => 'base_plus_addon',
                    ]),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('duplicate')
                    ->icon('heroicon-o-document-duplicate'),
            ]);
    }

    /**
     * Convert form data to database format before save
     */
    public static function mutateFormDataBeforeSave(array $data): array
    {
        // Build pricing_config JSON from form fields
        $pricingConfig = ['base_price' => $data['base_price'] ?? 0];
        
        if ($data['pricing_model'] === 'linear') {
            $pricingConfig['memory_per_gb'] = $data['linear_memory_rate'] ?? 0;
            $pricingConfig['cpu_per_core'] = $data['linear_cpu_rate'] ?? 0;
            $pricingConfig['disk_per_gb'] = $data['linear_disk_rate'] ?? 0;
        } elseif ($data['pricing_model'] === 'tiered') {
            $pricingConfig['memory_tiers'] = $data['memory_tiers'] ?? [];
            // ... cpu_tiers, disk_tiers
        } elseif ($data['pricing_model'] === 'base_plus_addon') {
            $pricingConfig['included'] = [
                'memory_gb' => $data['included_memory'] ?? 0,
                'cpu_cores' => $data['included_cpu'] ?? 0,
                'disk_gb' => $data['included_disk'] ?? 0,
            ];
            $pricingConfig['overage'] = [
                'memory_per_gb' => $data['overage_memory'] ?? 0,
                'cpu_per_core' => $data['overage_cpu'] ?? 0,
                'disk_per_gb' => $data['overage_disk'] ?? 0,
            ];
        }
        
        $data['pricing_config'] = json_encode($pricingConfig);
        
        // Convert GB to MB for database storage
        $data['min_memory'] = ($data['min_memory_gb'] ?? 1) * 1024;
        $data['max_memory'] = ($data['max_memory_gb'] ?? 64) * 1024;
        $data['memory_step'] = ($data['memory_step_gb'] ?? 1) * 1024;
        $data['default_memory'] = ($data['default_memory_gb'] ?? 4) * 1024;
        
        $data['min_cpu'] = ($data['min_cpu_cores'] ?? 1) * 100;
        $data['max_cpu'] = ($data['max_cpu_cores'] ?? 8) * 100;
        $data['cpu_step'] = ($data['cpu_step_cores'] ?? 1) * 100;
        $data['default_cpu'] = ($data['default_cpu_cores'] ?? 2) * 100;
        
        $data['min_disk'] = ($data['min_disk_gb'] ?? 10) * 1024;
        $data['max_disk'] = ($data['max_disk_gb'] ?? 500) * 1024;
        $data['disk_step'] = ($data['disk_step_gb'] ?? 10) * 1024;
        $data['default_disk'] = ($data['default_disk_gb'] ?? 50) * 1024;
        
        // Build display_config JSON
        $data['display_config'] = json_encode([
            'memory_label' => $data['display_memory_label'] ?? 'RAM',
            'cpu_label' => $data['display_cpu_label'] ?? 'CPU Cores',
            'disk_label' => $data['display_disk_label'] ?? 'Storage',
            'price_format' => $data['display_price_format'] ?? 'monthly',
            'show_breakdown' => $data['show_price_breakdown'] ?? true,
        ]);
        
        return $data;
    }

    private static function getLocationOptions(): array
    {
        try {
            return app(ResourceCalculationService::class)
                ->getLocations()
                ->pluck('long', 'id')
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
```

---

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

## Settings Page

**File**: `Filament/Pages/Settings.php`

```php
<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class Settings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Dynamic Pterodactyl';
    protected static ?int $navigationSort = 20;
    protected static string $view = 'dynamic-pterodactyl::admin.settings';
    
    public ?array $data = [];
    
    public function mount(): void
    {
        $config = \App\Helpers\ExtensionHelper::getConfig('Others', 'DynamicPterodactyl');
        $this->form->fill($config);
    }
    
    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Settings')->tabs([
                
                Forms\Components\Tabs\Tab::make('Connection')
                    ->schema([
                        Forms\Components\TextInput::make('pterodactyl_url')
                            ->label('Panel URL')
                            ->url()
                            ->required(),
                        Forms\Components\TextInput::make('pterodactyl_api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->required(),
                    ]),
                
                Forms\Components\Tabs\Tab::make('Defaults')
                    ->schema([
                        Forms\Components\TextInput::make('reservation_ttl')
                            ->suffix('minutes')
                            ->numeric()
                            ->default(15),
                        Forms\Components\Select::make('default_pricing_model')
                            ->options([
                                'linear' => 'Linear',
                                'tiered' => 'Tiered',
                                'base_plus_addon' => 'Base + Addon',
                            ])
                            ->default('linear'),
                    ]),
                
                Forms\Components\Tabs\Tab::make('Display')
                    ->schema([
                        Forms\Components\Select::make('currency')
                            ->options([
                                'USD' => 'US Dollar ($)',
                                'EUR' => 'Euro (€)',
                                'GBP' => 'British Pound (£)',
                            ])
                            ->default('USD'),
                        Forms\Components\ColorPicker::make('slider_color')
                            ->default('#3b82f6'),
                    ]),
                
            ])->columnSpanFull(),
        ])->statePath('data');
    }
    
    protected function getFormActions(): array
    {
        return [
            Action::make('test')
                ->label('Test Connection')
                ->color('secondary')
                ->action('testConnection'),
            Action::make('save')
                ->action('save'),
        ];
    }
    
    public function save(): void
    {
        $data = $this->form->getState();
        \App\Helpers\ExtensionHelper::setConfig('Others', 'DynamicPterodactyl', $data);
        
        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
    
    public function testConnection(): void
    {
        $result = app(ResourceCalculationService::class)->testConnection();
        
        Notification::make()
            ->title($result['success'] ? 'Connected' : 'Failed')
            ->body($result['message'])
            ->color($result['success'] ? 'success' : 'danger')
            ->send();
    }
}
```

---

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
            ])
            ->actions([
                Tables\Actions\Action::make('view_changes')
                    ->modalContent(fn ($record) => view('dynamic-pterodactyl::admin.audit-detail', [
                        'old' => json_decode($record->old_values, true),
                        'new' => json_decode($record->new_values, true),
                    ])),
            ]);
    }
}
```

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
