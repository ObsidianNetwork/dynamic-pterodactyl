<x-filament-panels::page>
    {{-- Connection Status Banner --}}
    @if(!$connectionStatus['success'])
        <div class="p-4 mb-6 bg-danger-100 dark:bg-danger-900/20 border border-danger-300 dark:border-danger-700 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-exclamation-circle class="w-6 h-6 text-danger-600 dark:text-danger-400 mr-3" />
                <div>
                    <h3 class="font-semibold text-danger-800 dark:text-danger-200">Pterodactyl Connection Failed</h3>
                    <p class="text-danger-700 dark:text-danger-300">{{ $connectionStatus['message'] }}</p>
                </div>
            </div>
        </div>
    @else
        <div class="p-4 mb-6 bg-success-100 dark:bg-success-900/20 border border-success-300 dark:border-success-700 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400 mr-3" />
                <span class="font-semibold text-success-800 dark:text-success-200">Connected</span>
                <span class="text-success-700 dark:text-success-300 ml-2">{{ $connectionStatus['node_count'] }} nodes available</span>
            </div>
        </div>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Products with Sliders</div>
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $productsWithSliders }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Pending Reservations</div>
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $pendingReservations }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">30-Day Confirmed Revenue</div>
            <div class="space-y-1 text-xl font-bold text-gray-900 dark:text-white">
                @forelse(($stats['confirmed_revenue_by_currency'] ?? []) as $currency => $amount)
                    <div>{{ $currency }} {{ $amount }}</div>
                @empty
                    <div>—</div>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">Conversion Rate</div>
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $stats['conversion_rate'] ?? 0 }}%</div>
        </x-filament::section>
    </div>

    {{-- Location Capacity --}}
    <x-filament::section>
        <x-slot name="heading">
            Location Capacity
        </x-slot>

        <div class="space-y-4">
            @forelse($locations as $location)
                <div class="border dark:border-gray-700 rounded-lg p-4">
                    <div class="flex justify-between items-center mb-2">
                        <h3 class="font-medium text-gray-900 dark:text-white">{{ $location['long'] }}</h3>
                        <span class="px-2 py-1 text-xs rounded-full
                            {{ $location['health'] === 'healthy' ? 'bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400' : '' }}
                            {{ $location['health'] === 'warning' ? 'bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-400' : '' }}
                            {{ $location['health'] === 'critical' ? 'bg-danger-100 text-danger-800 dark:bg-danger-900/30 dark:text-danger-400' : '' }}">
                            {{ ucfirst($location['health']) }}
                        </span>
                    </div>

                    @php
                        $memPercent = $location['capacity']['total_capacity']['memory'] > 0
                            ? ($location['capacity']['total_allocated']['memory'] / $location['capacity']['total_capacity']['memory']) * 100
                            : 0;
                        $diskPercent = $location['capacity']['total_capacity']['disk'] > 0
                            ? ($location['capacity']['total_allocated']['disk'] / $location['capacity']['total_capacity']['disk']) * 100
                            : 0;
                    @endphp

                    <div class="mb-2">
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-1">
                            <span>Memory</span>
                            <span>{{ number_format($location['capacity']['total_allocated']['memory'] / 1024, 1) }}GB /
                                  {{ number_format($location['capacity']['total_capacity']['memory'] / 1024, 1) }}GB</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $memPercent >= 95 ? 'bg-danger-500' : ($memPercent >= 80 ? 'bg-warning-500' : 'bg-success-500') }}"
                                 style="width: {{ min($memPercent, 100) }}%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-1">
                            <span>Disk</span>
                            <span>{{ number_format($location['capacity']['total_allocated']['disk'] / 1024, 1) }}GB /
                                  {{ number_format($location['capacity']['total_capacity']['disk'] / 1024, 1) }}GB</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $diskPercent >= 95 ? 'bg-danger-500' : ($diskPercent >= 80 ? 'bg-warning-500' : 'bg-success-500') }}"
                                 style="width: {{ min($diskPercent, 100) }}%"></div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-gray-500 dark:text-gray-400">No locations available. Check your Pterodactyl connection.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
