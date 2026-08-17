<?php

namespace App\Filament\Resources;

use App\Enums\TopUpStatus;
use App\Filament\Resources\TopUpResource\Pages;
use App\Models\TopUp;
use App\Models\User;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TopUpResource extends Resource
{
    protected static ?string $model = TopUp::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'المالية';
    }

    public static function getNavigationLabel(): string
    {
        return 'طلبات الشحن';
    }

    public static function getModelLabel(): string
    {
        return 'طلب شحن';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات الشحن';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('wallet.user.name')->label('العميل')->searchable(),
                TextColumn::make('amount')->label('المبلغ')->sortable(),
                TextColumn::make('gateway_reference')->label('رقم الحوالة')->copyable()->searchable(),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        TopUpStatus::Pending->value => 'قيد المراجعة',
                        TopUpStatus::Succeeded->value => 'مقبول',
                        TopUpStatus::Rejected->value => 'مرفوض',
                    ])
                    ->default(TopUpStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label('عرض الإيصال')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible(fn (TopUp $record): bool => $record->receipt_url !== null)
                    ->url(fn (TopUp $record): string => route('admin.deposits.receipt', $record))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('قبول وشحن')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TopUp $record): bool => $record->status === TopUpStatus::Pending)
                    ->fillForm(fn (TopUp $record): array => ['amount' => (string) $record->amount])
                    ->schema([
                        // Prefilled with the requested amount; the admin can correct it to the receipt.
                        TextInput::make('amount')->label('المبلغ المؤكَّد')->numeric()->required()->minValue(0),
                    ])
                    ->action(function (array $data, TopUp $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        try {
                            app(DepositService::class)->approve($record, $admin, (string) $data['amount']);
                            Notification::make()->title('تم قبول الشحن وإضافة المبلغ')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (TopUp $record): bool => $record->status === TopUpStatus::Pending)
                    ->action(function (TopUp $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        try {
                            app(DepositService::class)->reject($record, $admin);
                            Notification::make()->title('تم رفض طلب الشحن')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopUps::route('/'),
        ];
    }
}
