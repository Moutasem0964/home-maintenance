<?php

namespace App\Filament\Resources;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Filament\Resources\DisputeResource\Pages;
use App\Models\Dispute;
use App\Models\User;
use App\Services\DisputeService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'الإشراف';
    }

    public static function getNavigationLabel(): string
    {
        return 'النزاعات';
    }

    public static function getModelLabel(): string
    {
        return 'نزاع';
    }

    public static function getPluralModelLabel(): string
    {
        return 'النزاعات';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('order_id')->label('الطلب')->sortable(),
                TextColumn::make('raiser.name')->label('مقدّم النزاع')->searchable(),
                TextColumn::make('reason')->label('السبب')->badge(),
                TextColumn::make('description')->label('التفاصيل')->limit(40)->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('status')->label('الحالة')->badge(),
                TextColumn::make('created_at')->label('التاريخ')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        DisputeStatus::Open->value => 'مفتوح',
                        DisputeStatus::UnderReview->value => 'قيد المراجعة',
                        DisputeStatus::Escalated->value => 'مُصعّد',
                        DisputeStatus::Resolved->value => 'محلول',
                    ])
                    ->default(DisputeStatus::Open->value),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('حل النزاع')
                    ->icon('heroicon-o-check-circle')
                    ->color('primary')
                    ->visible(fn (Dispute $record): bool => $record->status !== DisputeStatus::Resolved)
                    ->schema([
                        Select::make('resolution')
                            ->label('القرار')
                            ->options([
                                DisputeResolution::FullRefund->value => 'استرداد كامل للعميل',
                                DisputeResolution::PartialRefund->value => 'استرداد جزئي',
                                DisputeResolution::ReleaseToTechnician->value => 'صرف المبلغ للفني',
                                DisputeResolution::WarrantyOrder->value => 'زيارة إصلاح مجانية (ضمان)',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('refund_amount')
                            ->label('مبلغ الاسترداد')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => $get('resolution') === DisputeResolution::PartialRefund->value)
                            ->required(fn (Get $get): bool => $get('resolution') === DisputeResolution::PartialRefund->value),
                    ])
                    ->action(function (array $data, Dispute $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        $refund = ($data['refund_amount'] ?? null) !== null ? (string) $data['refund_amount'] : null;
                        try {
                            app(DisputeService::class)->resolve(
                                $record,
                                $admin,
                                DisputeResolution::from((string) $data['resolution']),
                                $refund,
                            );
                            Notification::make()->title('تم حل النزاع')->success()->send();
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
            'index' => Pages\ListDisputes::route('/'),
        ];
    }
}
