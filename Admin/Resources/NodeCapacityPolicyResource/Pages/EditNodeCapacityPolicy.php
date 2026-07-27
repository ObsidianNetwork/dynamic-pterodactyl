<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\NodeCapacityPolicyResource;

class EditNodeCapacityPolicy extends EditRecord
{
    protected static string $resource = NodeCapacityPolicyResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return NodeCapacityPolicyResource::withInventoryIdentity($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
