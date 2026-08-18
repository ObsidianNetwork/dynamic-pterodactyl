<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource;

class CreateNodeCapacityPolicy extends CreateRecord
{
    protected static string $resource = NodeCapacityPolicyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return NodeCapacityPolicyResource::withInventoryIdentity($data);
    }
}
