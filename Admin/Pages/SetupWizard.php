<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Pages;

use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ConfigOptionSetupService;
use Paymenter\Extensions\Others\DynamicPterodactyl\Services\ResourceCalculationService;

class SetupWizard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Dynamic Pterodactyl';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'dynamic-pterodactyl/setup-wizard';

    protected static ?string $title = 'Setup Wizard';

    protected static ?string $navigationLabel = 'Setup Wizard';

    protected string $view = 'dynamic-pterodactyl::admin.setup-wizard';

    public ?array $data = [];

    public ?array $existingOptions = null;

    public bool $showOverwriteWarning = false;

    public function mount(): void
    {
        $this->form->fill([
            'pricing_model' => 'linear',
            'base_price' => 0,
            'enable_memory_slider' => true,
            'enable_cpu_slider' => true,
            'enable_disk_slider' => true,
            // Memory defaults (in GB for display)
            'memory_min' => 1,
            'memory_max' => 64,
            'memory_step' => 1,
            'memory_default' => 4,
            // CPU defaults (in cores for display)
            'cpu_min' => 1,
            'cpu_max' => 8,
            'cpu_step' => 1,
            'cpu_default' => 2,
            // Disk defaults (in GB for display)
            'disk_min' => 10,
            'disk_max' => 500,
            'disk_step' => 10,
            'disk_default' => 50,
            // Linear rates
            'memory_rate' => 0.50,
            'cpu_rate' => 2.00,
            'disk_rate' => 0.02,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('Wizard')->tabs([

                    // TAB 1: Product Selection
                    Tabs\Tab::make('Product')
                        ->icon('heroicon-o-cube')
                        ->schema([
                            Select::make('product_id')
                                ->label('Select Product')
                                ->options(fn () => Product::pluck('name', 'id'))
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, ?int $state) {
                                    if ($state) {
                                        $this->checkExistingOptions($state);
                                    } else {
                                        $this->existingOptions = null;
                                        $this->showOverwriteWarning = false;
                                    }
                                }),

                            Placeholder::make('existing_warning')
                                ->label('')
                                ->content(fn () => $this->showOverwriteWarning
                                    ? '⚠️ This product already has ' . ($this->existingOptions['existing_count'] ?? 0) . ' dynamic slider config option(s). Running the wizard will update them.'
                                    : '')
                                ->visible(fn () => $this->showOverwriteWarning),

                            Section::make('Pricing Model')
                                ->schema([
                                    Select::make('pricing_model')
                                        ->options([
                                            'linear' => 'Linear (per-unit pricing)',
                                            'tiered' => 'Tiered (volume discounts)',
                                            'base_addon' => 'Base + Addon',
                                        ])
                                        ->default('linear')
                                        ->live()
                                        ->required(),

                                    TextInput::make('base_price')
                                        ->label('Base Price')
                                        ->prefix('$')
                                        ->numeric()
                                        ->default(0)
                                        ->helperText('Monthly base price before resource costs'),
                                ]),
                        ]),

                    // TAB 2: Resource Sliders
                    Tabs\Tab::make('Sliders')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->schema([
                            // Memory Slider
                            Section::make('Memory Slider')
                                ->schema([
                                    Toggle::make('enable_memory_slider')
                                        ->label('Enable Memory Slider')
                                        ->default(true)
                                        ->live(),
                                    Grid::make(4)
                                        ->visible(fn (Get $get) => $get('enable_memory_slider'))
                                        ->schema([
                                            TextInput::make('memory_min')
                                                ->label('Min')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(1),
                                            TextInput::make('memory_max')
                                                ->label('Max')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(64),
                                            TextInput::make('memory_step')
                                                ->label('Step')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(1),
                                            TextInput::make('memory_default')
                                                ->label('Default')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(4),
                                        ]),
                                ]),

                            // CPU Slider
                            Section::make('CPU Slider')
                                ->schema([
                                    Toggle::make('enable_cpu_slider')
                                        ->label('Enable CPU Slider')
                                        ->default(true)
                                        ->live(),
                                    Grid::make(4)
                                        ->visible(fn (Get $get) => $get('enable_cpu_slider'))
                                        ->schema([
                                            TextInput::make('cpu_min')
                                                ->label('Min')
                                                ->suffix('cores')
                                                ->numeric()
                                                ->default(1),
                                            TextInput::make('cpu_max')
                                                ->label('Max')
                                                ->suffix('cores')
                                                ->numeric()
                                                ->default(8),
                                            TextInput::make('cpu_step')
                                                ->label('Step')
                                                ->numeric()
                                                ->default(1),
                                            TextInput::make('cpu_default')
                                                ->label('Default')
                                                ->suffix('cores')
                                                ->numeric()
                                                ->default(2),
                                        ]),
                                ]),

                            // Disk Slider
                            Section::make('Disk Slider')
                                ->schema([
                                    Toggle::make('enable_disk_slider')
                                        ->label('Enable Disk Slider')
                                        ->default(true)
                                        ->live(),
                                    Grid::make(4)
                                        ->visible(fn (Get $get) => $get('enable_disk_slider'))
                                        ->schema([
                                            TextInput::make('disk_min')
                                                ->label('Min')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(10),
                                            TextInput::make('disk_max')
                                                ->label('Max')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(500),
                                            TextInput::make('disk_step')
                                                ->label('Step')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(10),
                                            TextInput::make('disk_default')
                                                ->label('Default')
                                                ->suffix('GB')
                                                ->numeric()
                                                ->default(50),
                                        ]),
                                ]),
                        ]),

                    // TAB 3: Pricing Details
                    Tabs\Tab::make('Pricing')
                        ->icon('heroicon-o-currency-dollar')
                        ->schema([
                            // LINEAR pricing
                            Section::make('Linear Rates')
                                ->description('Set price per unit for each resource type')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'linear')
                                ->schema([
                                    Grid::make(3)->schema([
                                        TextInput::make('memory_rate')
                                            ->label('Memory Rate')
                                            ->prefix('$')
                                            ->suffix('/GB/mo')
                                            ->numeric()
                                            ->default(0.50),
                                        TextInput::make('cpu_rate')
                                            ->label('CPU Rate')
                                            ->prefix('$')
                                            ->suffix('/core/mo')
                                            ->numeric()
                                            ->default(2.00),
                                        TextInput::make('disk_rate')
                                            ->label('Disk Rate')
                                            ->prefix('$')
                                            ->suffix('/GB/mo')
                                            ->numeric()
                                            ->default(0.02),
                                    ]),
                                ]),

                            // TIERED pricing
                            Section::make('Memory Tiers')
                                ->description('Volume discounts - price decreases as quantity increases')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'tiered')
                                ->schema([
                                    Repeater::make('memory_tiers')
                                        ->schema([
                                            TextInput::make('up_to')
                                                ->label('Up to (GB)')
                                                ->numeric()
                                                ->placeholder('∞ (leave empty for unlimited)'),
                                            TextInput::make('rate')
                                                ->label('Price per GB')
                                                ->prefix('$')
                                                ->numeric()
                                                ->required(),
                                        ])
                                        ->columns(2)
                                        ->addActionLabel('Add Tier')
                                        ->defaultItems(2)
                                        ->default([
                                            ['up_to' => 8, 'rate' => 0.75],
                                            ['up_to' => null, 'rate' => 0.50],
                                        ]),
                                ]),

                            Section::make('CPU Tiers')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'tiered')
                                ->schema([
                                    Repeater::make('cpu_tiers')
                                        ->schema([
                                            TextInput::make('up_to')
                                                ->label('Up to (cores)')
                                                ->numeric()
                                                ->placeholder('∞'),
                                            TextInput::make('rate')
                                                ->label('Price per core')
                                                ->prefix('$')
                                                ->numeric()
                                                ->required(),
                                        ])
                                        ->columns(2)
                                        ->addActionLabel('Add Tier')
                                        ->defaultItems(1)
                                        ->default([
                                            ['up_to' => null, 'rate' => 2.00],
                                        ]),
                                ]),

                            Section::make('Disk Tiers')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'tiered')
                                ->schema([
                                    Repeater::make('disk_tiers')
                                        ->schema([
                                            TextInput::make('up_to')
                                                ->label('Up to (GB)')
                                                ->numeric()
                                                ->placeholder('∞'),
                                            TextInput::make('rate')
                                                ->label('Price per GB')
                                                ->prefix('$')
                                                ->numeric()
                                                ->required(),
                                        ])
                                        ->columns(2)
                                        ->addActionLabel('Add Tier')
                                        ->defaultItems(1)
                                        ->default([
                                            ['up_to' => null, 'rate' => 0.02],
                                        ]),
                                ]),

                            // BASE + ADDON pricing
                            Section::make('Included Resources')
                                ->description('Resources included in base price')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'base_addon')
                                ->schema([
                                    Grid::make(3)->schema([
                                        TextInput::make('memory_included')
                                            ->label('Included Memory')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(4),
                                        TextInput::make('cpu_included')
                                            ->label('Included CPU')
                                            ->suffix('cores')
                                            ->numeric()
                                            ->default(2),
                                        TextInput::make('disk_included')
                                            ->label('Included Disk')
                                            ->suffix('GB')
                                            ->numeric()
                                            ->default(50),
                                    ]),
                                ]),

                            Section::make('Overage Rates')
                                ->description('Price for resources beyond included amount')
                                ->visible(fn (Get $get) => $get('pricing_model') === 'base_addon')
                                ->schema([
                                    Grid::make(3)->schema([
                                        TextInput::make('memory_overage')
                                            ->label('Memory Overage')
                                            ->prefix('$')
                                            ->suffix('/GB/mo')
                                            ->numeric()
                                            ->default(0.75),
                                        TextInput::make('cpu_overage')
                                            ->label('CPU Overage')
                                            ->prefix('$')
                                            ->suffix('/core/mo')
                                            ->numeric()
                                            ->default(3.00),
                                        TextInput::make('disk_overage')
                                            ->label('Disk Overage')
                                            ->prefix('$')
                                            ->suffix('/GB/mo')
                                            ->numeric()
                                            ->default(0.05),
                                    ]),
                                ]),
                        ]),

                    // TAB 4: Locations
                    Tabs\Tab::make('Locations')
                        ->icon('heroicon-o-map-pin')
                        ->schema([
                            Section::make('Location Selection')
                                ->description('Select which Pterodactyl locations to enable for this product')
                                ->schema([
                                    Select::make('locations')
                                        ->label('Allowed Locations')
                                        ->multiple()
                                        ->options(fn () => $this->getLocationOptions())
                                        ->helperText('Select locations to create a location selector. Leave empty to skip location config option.'),
                                ]),
                        ]),

                ])->columnSpanFull(),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setup')
                ->label('Create Config Options')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading(fn () => $this->showOverwriteWarning
                    ? 'Update Existing Config Options?'
                    : 'Create Config Options')
                ->modalDescription(fn () => $this->showOverwriteWarning
                    ? 'This product already has dynamic slider config options. They will be updated with the new settings.'
                    : 'This will create dynamic slider config options for Memory, CPU, and Disk on the selected product.')
                ->action(fn () => $this->setupConfigOptions()),
        ];
    }

    public function setupConfigOptions(): void
    {
        $data = $this->form->getState();

        if (empty($data['product_id'])) {
            Notification::make()
                ->title('Please select a product')
                ->warning()
                ->send();

            return;
        }

        try {
            // Get locations from Pterodactyl
            $selectedLocationIds = $data['locations'] ?? [];
            $locations = [];

            if (! empty($selectedLocationIds)) {
                $allLocations = app(ResourceCalculationService::class)->getLocations();
                $locations = array_filter($allLocations, fn ($loc) => in_array($loc['id'], $selectedLocationIds));
            }

            // Call the service to create config options
            $created = app(ConfigOptionSetupService::class)->createDynamicSliderOptions(
                (int) $data['product_id'],
                $data,
                $locations
            );

            $optionCount = count(array_filter($created, fn ($v) => $v !== null));

            Notification::make()
                ->title('Config Options Created Successfully')
                ->body("Created/updated {$optionCount} config option(s) for the product.")
                ->success()
                ->send();

            // Reset form
            $this->existingOptions = null;
            $this->showOverwriteWarning = false;

        } catch (\Exception $e) {
            Notification::make()
                ->title('Failed to Create Config Options')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function checkExistingOptions(int $productId): void
    {
        $service = app(ConfigOptionSetupService::class);
        $this->existingOptions = $service->checkExistingOptions($productId);
        $this->showOverwriteWarning = $this->existingOptions['has_existing'] ?? false;
    }

    protected function getLocationOptions(): array
    {
        try {
            $locations = app(ResourceCalculationService::class)->getLocations();

            return collect($locations)->mapWithKeys(fn ($loc) => [
                $loc['id'] => ($loc['long'] ?: $loc['short']) . ' (ID: ' . $loc['id'] . ')',
            ])->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }
}
