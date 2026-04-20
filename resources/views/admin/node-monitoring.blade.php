<x-filament-panels::page>
    @if($error)
        <div class="p-4 mb-6 bg-danger-100 dark:bg-danger-900/20 border border-danger-300 dark:border-danger-700 rounded-lg">
            <div class="flex items-center">
                <x-heroicon-o-exclamation-circle class="w-6 h-6 text-danger-600 dark:text-danger-400 mr-3" />
                <div>
                    <h3 class="font-semibold text-danger-800 dark:text-danger-200">Error Loading Nodes</h3>
                    <p class="text-danger-700 dark:text-danger-300">{{ $error }}</p>
                </div>
            </div>
        </div>
    @endif

    <div class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Last updated: {{ $lastUpdated->format('Y-m-d H:i:s') }}
    </div>

    @forelse($locations as $location)
        <x-filament::section class="mb-6">
            <x-slot name="heading">
                {{ $location['name'] }} ({{ $location['short'] }})
            </x-slot>
            <x-slot name="description">
                {{ count($location['nodes']) }} node(s)
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b dark:border-gray-700">
                            <th class="text-left py-2 px-3 font-medium text-gray-700 dark:text-gray-300">Node</th>
                            <th class="text-left py-2 px-3 font-medium text-gray-700 dark:text-gray-300">Status</th>
                            <th class="text-left py-2 px-3 font-medium text-gray-700 dark:text-gray-300">Memory</th>
                            <th class="text-left py-2 px-3 font-medium text-gray-700 dark:text-gray-300">Disk</th>
                            <th class="text-left py-2 px-3 font-medium text-gray-700 dark:text-gray-300">Servers</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($location['nodes'] as $node)
                            @php
                                $memPercent = $node['utilization']['memory'] ?? 0;
                                $diskPercent = $node['utilization']['disk'] ?? 0;
                            @endphp
                            <tr class="border-b dark:border-gray-700">
                                <td class="py-3 px-3">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $node['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $node['fqdn'] }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    @if($node['maintenance_mode'])
                                        <span class="px-2 py-1 text-xs rounded-full bg-warning-100 text-warning-800 dark:bg-warning-900/30 dark:text-warning-400">
                                            Maintenance
                                        </span>
                                    @else
                                        <span class="px-2 py-1 text-xs rounded-full bg-success-100 text-success-800 dark:bg-success-900/30 dark:text-success-400">
                                            Online
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div class="h-2 rounded-full {{ $memPercent >= 95 ? 'bg-danger-500' : ($memPercent >= 80 ? 'bg-warning-500' : 'bg-success-500') }}"
                                                 style="width: {{ min($memPercent, 100) }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                            {{ round($memPercent, 1) }}%
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        {{ number_format($node['allocated']['memory'] / 1024, 1) }}GB /
                                        {{ number_format($node['total']['memory'] / 1024, 1) }}GB
                                        @if($node['reserved']['memory'] > 0)
                                            <span class="text-warning-600 dark:text-warning-400">
                                                (+{{ number_format($node['reserved']['memory'] / 1024, 1) }}GB reserved)
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-24 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div class="h-2 rounded-full {{ $diskPercent >= 95 ? 'bg-danger-500' : ($diskPercent >= 80 ? 'bg-warning-500' : 'bg-success-500') }}"
                                                 style="width: {{ min($diskPercent, 100) }}%"></div>
                                        </div>
                                        <span class="text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                            {{ round($diskPercent, 1) }}%
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        {{ number_format($node['allocated']['disk'] / 1024, 1) }}GB /
                                        {{ number_format($node['total']['disk'] / 1024, 1) }}GB
                                    </div>
                                </td>
                                <td class="py-3 px-3 text-gray-900 dark:text-white">
                                    {{ $node['server_count'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-gray-500 dark:text-gray-400">No locations found. Check your Pterodactyl connection settings.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
