<?php

namespace App\Filament\Resources\OrderCategories\Pages;

use App\Filament\Resources\OrderCategories\OrderCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderCategory extends CreateRecord
{
    protected static string $resource = OrderCategoryResource::class;
}
