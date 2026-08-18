<?php

namespace App\Filament\Resources;

use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use App\Filament\Resources\TechnicianFlagResource\Pages;
use App\Models\TechnicianFlag;
use App\Models\User;
use App\Services\TechnicianFlagService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TechnicianFlagResource extends Resource
{
    protected static ?string $model = TechnicianFlag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    /** Reasons that are genuine technician offenses (a ClientNoShow is a claim, resolved via the order). */
    private const OFFENSES = [
        TechnicianFlagReason::NoShow,
        TechnicianFlagReason::Withdrawal,
        TechnicianFlagReason::PartsDelay,
    ];

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'الإشراف';
    }

    public static function getNavigationLabel(): string
    {
        return 'مخالفات الفنيين';
    }

    public static function getModelLabel(): string
    {
        return 'مخالفة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مخالفات الفنيين';
    }

    private static function reasonLabel(TechnicianFlagReason $reason): string
    {
        return match ($reason) {
            TechnicianFlagReason::NoShow => 'عدم حضور الفني',
            TechnicianFlagReason::ClientNoShow => 'ادعاء غياب العميل',
            TechnicianFlagReason::Withdrawal => 'انسحاب من طلب',
            TechnicianFlagReason::PartsDelay => 'تأخر قطع الغيار',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('technician.user.name')->label('الفني')->searchable(),
                TextColumn::make('reason')->label('السبب')->badge()
                    ->formatStateUsing(fn (TechnicianFlagReason $state): string => self::reasonLabel($state)),
                TextColumn::make('order_id')->label('الطلب')->placeholder('—'),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('outcome')->label('القرار')->badge()->placeholder('—'),
                TextColumn::make('note')->label('ملاحظة')->limit(30)->placeholder('—'),
                TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        TechnicianFlagStatus::Open->value => 'مفتوحة',
                        TechnicianFlagStatus::Reviewed->value => 'مُراجَعة',
                    ])
                    ->default(TechnicianFlagStatus::Open->value),
                SelectFilter::make('reason')
                    ->label('السبب')
                    ->options([
                        TechnicianFlagReason::NoShow->value => 'عدم حضور الفني',
                        TechnicianFlagReason::Withdrawal->value => 'انسحاب من طلب',
                        TechnicianFlagReason::PartsDelay->value => 'تأخر قطع الغيار',
                        TechnicianFlagReason::ClientNoShow->value => 'ادعاء غياب العميل',
                    ]),
            ])
            ->recordActions([
                Action::make('dismiss')
                    ->label('تجاهل')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->visible(fn (TechnicianFlag $record): bool => $record->status === TechnicianFlagStatus::Open && in_array($record->reason, self::OFFENSES, true))
                    ->schema([
                        Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength(2000),
                    ])
                    ->action(function (array $data, TechnicianFlag $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(TechnicianFlagService::class)->resolve($record, $admin, TechnicianFlagOutcome::Dismissed, $data['note'] ?? null);
                        Notification::make()->title('تم تجاهل المخالفة')->success()->send();
                    }),
                Action::make('uphold')
                    ->label('تأكيد المخالفة')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (TechnicianFlag $record): bool => $record->status === TechnicianFlagStatus::Open && in_array($record->reason, self::OFFENSES, true))
                    ->schema([
                        Textarea::make('note')->label('ملاحظة (اختياري)')->maxLength(2000),
                    ])
                    ->action(function (array $data, TechnicianFlag $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(TechnicianFlagService::class)->resolve($record, $admin, TechnicianFlagOutcome::Upheld, $data['note'] ?? null);
                        Notification::make()->title('تم تأكيد المخالفة (بدون عقوبة تلقائية — استخدم صفحة الفنيين للإيقاف/الحظر)')->success()->send();
                    }),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTechnicianFlags::route('/'),
        ];
    }
}
