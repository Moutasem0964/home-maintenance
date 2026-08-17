<?php

namespace App\Filament\Resources;

use App\Enums\TechnicianStatus;
use App\Filament\Resources\TechnicianResource\Pages;
use App\Models\Technician;
use App\Models\User;
use App\Services\TechnicianModerationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TechnicianResource extends Resource
{
    protected static ?string $model = Technician::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'الإشراف';
    }

    public static function getNavigationLabel(): string
    {
        return 'الفنيون';
    }

    public static function getModelLabel(): string
    {
        return 'فني';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الفنيون';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('user.name')->label('الاسم')->searchable(),
                TextColumn::make('user.phone')->label('الهاتف')->searchable(),
                TextColumn::make('status')->label('الحالة')->badge(),
                IconColumn::make('is_available')->label('متاح')->boolean(),
                TextColumn::make('rating_avg')->label('التقييم')->sortable(),
                TextColumn::make('created_at')->label('انضم في')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        TechnicianStatus::Pending->value => 'قيد المراجعة',
                        TechnicianStatus::Probation->value => 'تحت التجربة',
                        TechnicianStatus::Active->value => 'فعّال',
                        TechnicianStatus::Banned->value => 'محظور',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('اعتماد')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Technician $record): bool => $record->status === TechnicianStatus::Pending)
                    ->action(function (Technician $record): void {
                        app(TechnicianModerationService::class)->approve($record);
                        Notification::make()->title('تم اعتماد الفني')->success()->send();
                    }),
                Action::make('suspend')
                    ->label('إيقاف مؤقت')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn (Technician $record): bool => in_array($record->status, [TechnicianStatus::Active, TechnicianStatus::Probation], true))
                    ->schema([
                        Textarea::make('note')->label('السبب (اختياري)')->maxLength(2000),
                    ])
                    ->action(function (array $data, Technician $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(TechnicianModerationService::class)->suspend($record, $admin, $data['note'] ?? null);
                        Notification::make()->title('تم إيقاف الفني مؤقتاً')->success()->send();
                    }),
                Action::make('ban')
                    ->label('حظر')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Technician $record): bool => $record->status !== TechnicianStatus::Banned)
                    ->schema([
                        Textarea::make('note')->label('السبب (اختياري)')->maxLength(2000),
                    ])
                    ->action(function (array $data, Technician $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(TechnicianModerationService::class)->ban($record, $admin, $data['note'] ?? null);
                        Notification::make()->title('تم حظر الفني')->success()->send();
                    }),
            ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTechnicians::route('/'),
        ];
    }
}
