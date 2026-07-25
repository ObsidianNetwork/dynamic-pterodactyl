<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource;

class ListNodeCapacityPolicies extends ListRecords
{
    protected static string $resource = NodeCapacityPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
