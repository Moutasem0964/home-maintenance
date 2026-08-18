<?php

namespace App\Filament\Resources\ServiceCategoryResource\Pages;

use App\Filament\Resources\ServiceCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceCategories extends ListRecords
{
    protected static string $resource = ServiceCategoryResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('تصنيف جديد'),
        ];
    }
}
