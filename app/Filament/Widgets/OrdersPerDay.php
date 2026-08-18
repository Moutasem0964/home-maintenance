<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class OrdersPerDay extends ChartWidget
{
    protected ?string $heading = 'الطلبات خلال آخر ١٤ يوم';

    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $start = Carbon::today()->subDays(13);

        $counts = Order::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn (Order $order): string => (string) $order->created_at?->toDateString())
            ->map->count();

        $labels = [];
        $data = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = Carbon::today()->subDays($i);
            $labels[] = $day->format('m-d');
            $data[] = $counts->get($day->toDateString(), 0);
        }

        return [
            'datasets' => [[
                'label' => 'عدد الطلبات',
                'data' => $data,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
