<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AppSettingResource\Pages;
use App\Models\AppSetting;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Tunable platform settings — values are editable; keys are fixed (seeded), so no create/delete. */
class AppSettingResource extends Resource
{
    protected static ?string $model = AppSetting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات التطبيق';
    }

    public static function getModelLabel(): string
    {
        return 'إعداد';
    }

    public static function getPluralModelLabel(): string
    {
        return 'إعدادات التطبيق';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label('المفتاح')->disabled(),
            TextInput::make('data_type')->label('نوع القيمة')->disabled(),
            TextInput::make('value')->label('القيمة')->required(),
            Textarea::make('description')->label('الوصف')->disabled()->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label('المفتاح')->searchable(),
                TextColumn::make('description')->label('الوصف')->limit(60)->wrap(),
                TextColumn::make('value')->label('القيمة')->badge(),
                TextColumn::make('data_type')->label('النوع')->badge(),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppSettings::route('/'),
            'edit' => Pages\EditAppSetting::route('/{record}/edit'),
        ];
    }
}
