<?php

namespace Rejoose\ModelCounter\Filament\Resources\ModelCounterResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Rejoose\ModelCounter\Filament\Resources\ModelCounterResource;

class EditModelCounter extends EditRecord
{
    protected static string $resource = ModelCounterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
