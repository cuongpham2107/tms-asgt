<?php

namespace App\Filament\Resources\OrderTemplates\Pages;

use App\Filament\Resources\OrderTemplates\OrderTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderTemplate extends CreateRecord
{
    protected static string $resource = OrderTemplateResource::class;
}
