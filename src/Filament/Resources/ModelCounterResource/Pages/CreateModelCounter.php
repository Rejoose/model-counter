<?php

namespace Rejoose\ModelCounter\Filament\Resources\ModelCounterResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Rejoose\ModelCounter\Filament\Resources\ModelCounterResource;

class CreateModelCounter extends CreateRecord
{
    protected static string $resource = ModelCounterResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
