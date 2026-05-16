<?php

namespace App\Filament\Resources\OrderTypes\Pages;

use App\Filament\Resources\OrderTypes\OrderTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderType extends CreateRecord
{
    protected static string $resource = OrderTypeResource::class;
}
