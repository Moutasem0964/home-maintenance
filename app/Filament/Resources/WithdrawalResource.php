<?php

namespace App\Filament\Resources;

use App\Enums\WithdrawalStatus;
use App\Filament\Resources\WithdrawalResource\Pages;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\WithdrawalService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WithdrawalResource extends Resource
{
    protected static ?string $model = Withdrawal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'المالية';
    }

    public static function getNavigationLabel(): string
    {
        return 'طلبات السحب';
    }

    public static function getModelLabel(): string
    {
        return 'طلب سحب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'طلبات السحب';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('technician.user.name')->label('الفني')->searchable(),
                TextColumn::make('amount')->label('المبلغ')->sortable(),
                TextColumn::make('destination_details')->label('رقم شام كاش')->copyable(),
                TextColumn::make('destination_name')->label('اسم صاحب الحساب')->toggleable(),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        WithdrawalStatus::Processing->value => 'قيد المعالجة',
                        WithdrawalStatus::Completed->value => 'مكتمل',
                        WithdrawalStatus::Rejected->value => 'مرفوض',
                    ])
                    ->default(WithdrawalStatus::Processing->value),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('تأكيد الدفع')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Processing)
                    ->schema([
                        FileUpload::make('receipt')
                            ->label('إيصال التحويل')
                            ->image()
                            ->required()
                            ->disk('local')
                            ->directory('withdrawal-receipts'),
                    ])
                    ->action(function (array $data, Withdrawal $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        try {
                            app(WithdrawalService::class)->complete($record, $admin, (string) $data['receipt']);
                            Notification::make()->title('تم تأكيد الدفع وتحويل المبلغ')->success()->send();
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('reject')
                    ->label('رفض')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Withdrawal $record): bool => $record->status === WithdrawalStatus::Processing)
                    ->action(function (Withdrawal $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        try {
                            app(WithdrawalService::class)->reject($record, $admin);
                            Notification::make()->title('تم رفض الطلب وإعادة المبلغ للرصيد')->success()->send();
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
            'index' => Pages\ListWithdrawals::route('/'),
        ];
    }
}
