<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceCategoryResource\Pages;
use App\Models\ServiceCategory;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServiceCategoryResource extends Resource
{
    protected static ?string $model = ServiceCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'الإعدادات';
    }

    public static function getNavigationLabel(): string
    {
        return 'تصنيفات الخدمات';
    }

    public static function getModelLabel(): string
    {
        return 'تصنيف';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تصنيفات الخدمات';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            Select::make('parent_id')
                ->label('التصنيف الأب')
                ->relationship('parent', 'name')
                ->searchable()
                ->preload()
                ->placeholder('لا شيء (تصنيف رئيسي)'),
            TextInput::make('guide_price')
                ->label('السعر الاسترشادي')
                ->numeric()
                ->minValue(0)
                ->helperText('للتصنيفات القابلة للحجز (الأوراق) فقط.'),
            TextInput::make('icon_url')->label('رابط الأيقونة')->url()->maxLength(2048),
            Toggle::make('is_active')->label('مُفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('parent.name')->label('التصنيف الأب')->placeholder('— رئيسي'),
                TextColumn::make('guide_price')->label('السعر الاسترشادي')->placeholder('—'),
                TextColumn::make('children_count')->label('التصنيفات الفرعية')->counts('children'),
                IconColumn::make('is_active')->label('مُفعّل')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مُفعّل'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')
                    // Only leaf, unused categories can be deleted (avoids FK breakage).
                    ->visible(fn (ServiceCategory $record): bool => $record->children()->doesntExist() && $record->orders()->doesntExist()),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceCategories::route('/'),
            'create' => Pages\CreateServiceCategory::route('/create'),
            'edit' => Pages\EditServiceCategory::route('/{record}/edit'),
        ];
    }
}
