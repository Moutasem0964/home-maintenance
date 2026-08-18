<?php

namespace App\Filament\Resources;

use App\Enums\OrderKind;
use App\Enums\OrderType;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only monitoring of every order — no admin mutation of orders here by design. */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'المراقبة';
    }

    public static function getNavigationLabel(): string
    {
        return 'الطلبات';
    }

    public static function getModelLabel(): string
    {
        return 'طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطلبات';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'pending' => 'قيد الانتظار',
        'scheduled' => 'مجدول',
        'accepted' => 'مقبول',
        'in_progress' => 'قيد التنفيذ',
        'waiting_for_parts' => 'بانتظار قطع',
        'completed' => 'مكتمل',
        'disputed' => 'متنازع عليه',
        'canceled' => 'ملغى',
        'inspection_only' => 'كشف فقط',
        'no_show' => 'عدم حضور',
        'expired' => 'منتهٍ',
        'resolved' => 'محلول',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('client.name')->label('العميل')->searchable(),
                TextColumn::make('technician.user.name')->label('الفني')->placeholder('غير مُعيّن')->searchable(),
                TextColumn::make('serviceCategory.name')->label('الخدمة'),
                TextColumn::make('type')->label('النوع')->badge(),
                TextColumn::make('kind')->label('الصنف')->badge(),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(self::STATUS_LABELS),
                SelectFilter::make('type')->label('النوع')->options([
                    OrderType::Urgent->value => 'عاجل',
                    OrderType::Scheduled->value => 'مجدول',
                ]),
                SelectFilter::make('kind')->label('الصنف')->options([
                    OrderKind::Normal->value => 'عادي',
                    OrderKind::Warranty->value => 'ضمان',
                    OrderKind::Addon->value => 'إضافي',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الطرفان والخدمة')
                ->columns(2)
                ->schema([
                    TextEntry::make('client.name')->label('العميل'),
                    TextEntry::make('client.phone')->label('هاتف العميل'),
                    TextEntry::make('technician.user.name')->label('الفني')->placeholder('غير مُعيّن'),
                    TextEntry::make('serviceCategory.name')->label('الخدمة'),
                ]),
            Section::make('الحالة')
                ->columns(3)
                ->schema([
                    TextEntry::make('kind')->label('الصنف')->badge(),
                    TextEntry::make('type')->label('النوع')->badge(),
                    TextEntry::make('status')->label('الحالة')->badge(),
                    TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
                ]),
            Section::make('الموقع والتواريخ')
                ->columns(2)
                ->schema([
                    TextEntry::make('address.label')->label('العنوان')->placeholder('—'),
                    TextEntry::make('scheduled_at')->label('موعد الجدولة')->dateTime()->placeholder('—'),
                    TextEntry::make('arrived_at')->label('وقت الوصول')->dateTime()->placeholder('—'),
                    TextEntry::make('closure_verified_at')->label('تأكيد الإغلاق')->dateTime()->placeholder('—'),
                    TextEntry::make('dispute_deadline_at')->label('مهلة النزاع')->dateTime()->placeholder('—'),
                    TextEntry::make('warranty_until')->label('الضمان حتى')->dateTime()->placeholder('—'),
                ]),
            Section::make('المالية')
                ->columns(3)
                ->schema([
                    TextEntry::make('inspection_fee')->label('رسوم الكشف')->placeholder('—'),
                    TextEntry::make('commission_rate')->label('نسبة العمولة')->placeholder('—'),
                    TextEntry::make('commission_amount')->label('مبلغ العمولة')->placeholder('—'),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
