<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource;

class ListAlertConfigs extends ListRecords
{
    protected static string $resource = AlertConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
