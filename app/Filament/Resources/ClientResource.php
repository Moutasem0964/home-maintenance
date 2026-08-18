<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ClientResource\Pages;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Client (customer) accounts — monitoring, with the ability to ban/unban. */
class ClientResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'المراقبة';
    }

    public static function getNavigationLabel(): string
    {
        return 'العملاء';
    }

    public static function getModelLabel(): string
    {
        return 'عميل';
    }

    public static function getPluralModelLabel(): string
    {
        return 'العملاء';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<User> $query */
        $query = parent::getEloquentQuery()->where('role', UserRole::Client);

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('name')->label('الاسم')->searchable(),
                TextColumn::make('phone')->label('الهاتف')->searchable(),
                TextColumn::make('wallet.available_balance')->label('الرصيد')->placeholder('—'),
                TextColumn::make('orders_count')->label('الطلبات')->counts('orders'),
                IconColumn::make('is_banned')->label('محظور')->boolean(),
                TextColumn::make('created_at')->label('انضم في')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_banned')->label('محظور'),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
                Action::make('ban')
                    ->label('حظر')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is_banned)
                    ->action(function (User $record): void {
                        $record->is_banned = true;
                        $record->save();
                        $record->tokens()->delete(); // force logout — existing tokens stop working
                        Notification::make()->title('تم حظر العميل')->success()->send();
                    }),
                Action::make('unban')
                    ->label('رفع الحظر')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->is_banned)
                    ->action(function (User $record): void {
                        $record->is_banned = false;
                        $record->save();
                        Notification::make()->title('تم رفع الحظر عن العميل')->success()->send();
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الحساب')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->label('الاسم'),
                    TextEntry::make('phone')->label('الهاتف'),
                    TextEntry::make('is_banned')->label('الحالة')->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? 'محظور' : 'نشط')
                        ->color(fn (bool $state): string => $state ? 'danger' : 'success'),
                    TextEntry::make('created_at')->label('انضم في')->dateTime(),
                ]),
            Section::make('المحفظة')
                ->columns(2)
                ->schema([
                    TextEntry::make('wallet.available_balance')->label('الرصيد المتاح')->placeholder('—'),
                    TextEntry::make('wallet.held_balance')->label('الرصيد المحجوز')->placeholder('—'),
                    TextEntry::make('orders_count')->label('عدد الطلبات')->state(fn (User $record): int => $record->orders()->count()),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'view' => Pages\ViewClient::route('/{record}'),
        ];
    }
}
