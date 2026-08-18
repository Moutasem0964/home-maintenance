<?php

namespace App\Filament\Widgets;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\TechnicianFlagStatus;
use App\Enums\TechnicianStatus;
use App\Enums\TopUpStatus;
use App\Enums\WithdrawalStatus;
use App\Filament\Resources\DisputeResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\TechnicianFlagResource;
use App\Filament\Resources\TechnicianResource;
use App\Filament\Resources\TopUpResource;
use App\Filament\Resources\WithdrawalResource;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Technician;
use App\Models\TechnicianFlag;
use App\Models\TopUp;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStats extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $activeOrders = Order::whereIn('status', [
            OrderStatus::Accepted, OrderStatus::InProgress, OrderStatus::WaitingForParts,
        ])->count();

        $pendingWithdrawals = Withdrawal::where('status', WithdrawalStatus::Processing)->count();
        $pendingDeposits = TopUp::where('status', TopUpStatus::Pending)->count();
        $openDisputes = Dispute::where('status', '!=', DisputeStatus::Resolved)->count();
        $openFlags = TechnicianFlag::where('status', TechnicianFlagStatus::Open)->count();
        $workingTechs = Technician::whereIn('status', [TechnicianStatus::Active, TechnicianStatus::Probation])->count();

        $heldTotal = (float) Wallet::query()->sum('held_balance');
        $revenue = (float) Order::query()->where('status', OrderStatus::Completed)->sum('commission_amount');

        return [
            Stat::make('الطلبات النشطة', $activeOrders)
                ->icon('heroicon-o-bolt')->color('info')->url(OrderResource::getUrl()),
            Stat::make('طلبات سحب معلّقة', $pendingWithdrawals)
                ->icon('heroicon-o-banknotes')->color($pendingWithdrawals > 0 ? 'warning' : 'success')->url(WithdrawalResource::getUrl()),
            Stat::make('طلبات شحن معلّقة', $pendingDeposits)
                ->icon('heroicon-o-arrow-down-tray')->color($pendingDeposits > 0 ? 'warning' : 'success')->url(TopUpResource::getUrl()),
            Stat::make('نزاعات مفتوحة', $openDisputes)
                ->icon('heroicon-o-scale')->color($openDisputes > 0 ? 'danger' : 'success')->url(DisputeResource::getUrl()),
            Stat::make('مخالفات فنيين مفتوحة', $openFlags)
                ->icon('heroicon-o-flag')->color($openFlags > 0 ? 'warning' : 'success')->url(TechnicianFlagResource::getUrl()),
            Stat::make('الفنيون العاملون', $workingTechs)
                ->icon('heroicon-o-wrench-screwdriver')->color('info')->url(TechnicianResource::getUrl()),
            Stat::make('الأرصدة المحجوزة (ضمان)', number_format($heldTotal, 2))
                ->icon('heroicon-o-lock-closed')->color('gray'),
            Stat::make('إجمالي العمولات (طلبات مكتملة)', number_format($revenue, 2))
                ->icon('heroicon-o-currency-dollar')->color('success')->url(OrderResource::getUrl()),
        ];
    }
}
