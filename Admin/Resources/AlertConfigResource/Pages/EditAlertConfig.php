<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Paymenter\Extensions\Others\DynamicPterodactyl\Admin\Resources\AlertConfigResource;

class EditAlertConfig extends EditRecord
{
    protected static string $resource = AlertConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
