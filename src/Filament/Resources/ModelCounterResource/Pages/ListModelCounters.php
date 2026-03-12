<?php

namespace Rejoose\ModelCounter\Filament\Resources\ModelCounterResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Rejoose\ModelCounter\Filament\Resources\ModelCounterResource;

class ListModelCounters extends ListRecords
{
    protected static string $resource = ModelCounterResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
