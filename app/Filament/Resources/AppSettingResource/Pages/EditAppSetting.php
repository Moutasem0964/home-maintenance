<?php

namespace App\Filament\Resources\AppSettingResource\Pages;

use App\Filament\Resources\AppSettingResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditAppSetting extends EditRecord
{
    protected static string $resource = AppSettingResource::class;

    /** AppSetting::get() caches the resolved value for 5 min — bust it so the change takes effect now. */
    protected function afterSave(): void
    {
        Cache::forget('app_setting.'.(string) $this->record->getKey());
    }
}
