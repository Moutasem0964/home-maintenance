<?php

namespace Database\Seeders;

use App\Enums\DisputeReason;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\TechnicianFlagOutcome;
use App\Enums\TechnicianFlagReason;
use App\Enums\TechnicianFlagStatus;
use App\Enums\TechnicianStatus;
use App\Enums\TopUpStatus;
use App\Enums\UserRole;
use App\Enums\WithdrawalStatus;
use App\Models\Address;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Review;
use App\Models\ServiceCategory;
use App\Models\Technician;
use App\Models\TechnicianFlag;
use App\Models\TopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Seeder;

/**
 * Demo data for the defense — NOT for production baseline. Additive; run once:
 *   php artisan db:seed --class=DemoSeeder
 * Covers technicians in every status, orders across the lifecycle, disputes, flags,
 * withdrawals, deposits and reviews so the admin panel and app both show live data.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();

        /** @var list<ServiceCategory> $cats */
        $cats = ServiceCategory::whereDoesntHave('children')->get()->all();
        if ($cats === []) {
            $parent = ServiceCategory::factory()->create(['name' => 'صيانة عامة', 'is_active' => true]);
            $cats = ServiceCategory::factory()->count(4)->create([
                'parent_id' => $parent->id, 'is_active' => true,
            ])->all();
        }
        $cat = fn () => $cats[array_rand($cats)];

        // ---- Clients (each with a funded wallet + an address) ----
        $clients = [];
        foreach (['أحمد العلي', 'سارة حسن', 'محمد الخطيب', 'ليان قاسم', 'يوسف مراد', 'رهف ديب', 'عمر ناصر', 'جود سعيد'] as $i => $name) {
            $u = User::factory()->verified()->create(['name' => $name]);
            $this->wallet($u, (string) (($i % 4) * 250)); // 0 / 250 / 500 / 750 rotating
            Address::factory()->for($u)->create(['label' => 'المنزل', 'lat' => 33.51 + $i * 0.01, 'lng' => 36.29 + $i * 0.01]);
            $clients[] = $u;
        }
        $client = fn () => $clients[array_rand($clients)];

        // ---- Technicians across every status ----
        $active = [];
        // active + available (dispatchable)
        foreach (['خالد الفني', 'باسل التقني', 'وسيم صيانة', 'نور الميكانيكي'] as $j => $name) {
            $t = Technician::factory()->available()->create([
                'sham_cash_number' => '12345678'.str_pad((string) (9010 + $j), 8, '0', STR_PAD_LEFT),
                'sham_cash_name' => $name,
            ]);
            $t->rating_avg = number_format(4 + ($j % 2) * 0.5, 2, '.', ''); // not mass-assignable
            $t->saveQuietly();
            $t->user->update(['name' => $name]);
            $t->services()->attach($cat()->id);
            $this->wallet($t->user, (string) (300 + $j * 150));
            $active[] = $t;
        }
        // probation (trial), banned, pending (awaiting approval)
        foreach (['طارق تحت التجربة', 'سامر تحت التجربة'] as $name) {
            $t = Technician::factory()->create(['status' => TechnicianStatus::Probation, 'daily_order_limit' => 3, 'is_available' => true, 'current_lat' => 33.5, 'current_lng' => 36.3, 'location_updated_at' => now()]);
            $t->user->update(['name' => $name]);
            $t->services()->attach($cat()->id);
            $this->wallet($t->user, '120.00');
        }
        $banned = Technician::factory()->create(['status' => TechnicianStatus::Banned, 'is_available' => false]);
        $banned->user->update(['name' => 'فادي محظور']);
        foreach (['رامي قيد المراجعة', 'زياد قيد المراجعة', 'هاني قيد المراجعة'] as $name) {
            $t = Technician::factory()->create(); // pending by default
            $t->user->update(['name' => $name]);
            $t->services()->attach($cat()->id);
        }

        // ---- Orders across the lifecycle (back-dated over 14 days for the chart) ----
        $completed = [];
        $plan = [
            [OrderStatus::Pending, false], [OrderStatus::Pending, false], [OrderStatus::Scheduled, false],
            [OrderStatus::Accepted, true], [OrderStatus::Accepted, true], [OrderStatus::InProgress, true],
            [OrderStatus::InProgress, true], [OrderStatus::WaitingForParts, true],
            [OrderStatus::Completed, true], [OrderStatus::Completed, true], [OrderStatus::Completed, true],
            [OrderStatus::Disputed, true], [OrderStatus::Canceled, false], [OrderStatus::Expired, false],
            [OrderStatus::NoShow, true],
        ];
        foreach ($plan as $k => [$status, $assigned]) {
            $type = $k % 3 === 0 ? OrderType::Scheduled : OrderType::Urgent;
            $order = Order::factory()->create([
                'client_id' => $client()->id,
                'technician_id' => $assigned ? $active[array_rand($active)]->id : null,
                'service_category_id' => $cat()->id,
                'type' => $type,
                'status' => $status,
                'scheduled_at' => $type === OrderType::Scheduled ? now()->addDays(2) : null,
                'inspection_fee' => '50.00',
                'commission_rate' => '0.1000',
                'commission_amount' => $status === OrderStatus::Completed ? '35.00' : '0',
                'arrived_at' => in_array($status, [OrderStatus::InProgress, OrderStatus::WaitingForParts, OrderStatus::Completed, OrderStatus::Disputed], true) ? now()->subHours(3) : null,
                'warranty_until' => $status === OrderStatus::Completed ? now()->addDays(20) : null,
                'closure_verified_at' => $status === OrderStatus::Completed ? now()->subHours(1) : null,
            ]);
            $order->created_at = now()->subDays(random_int(0, 13))->subHours(random_int(0, 20));
            $order->saveQuietly();

            if ($status === OrderStatus::Completed) {
                $completed[] = $order;
            }
        }

        // A claimed warranty visit (kind = warranty) so the warranty tab has data
        if ($completed !== []) {
            $src = $completed[0];
            Order::factory()->create([
                'client_id' => $src->client_id, 'technician_id' => $src->technician_id,
                'service_category_id' => $src->service_category_id, 'parent_order_id' => $src->id,
                'kind' => OrderKind::Warranty, 'type' => OrderType::Scheduled,
                'status' => OrderStatus::Scheduled, 'scheduled_at' => now()->addDays(1),
                'inspection_fee' => '0', 'commission_amount' => '0',
            ]);
        }

        // ---- Reviews on completed orders ----
        foreach ($completed as $o) {
            Review::create([
                'order_id' => $o->id, 'client_id' => $o->client_id, 'technician_id' => $o->technician_id,
                'cleanliness' => random_int(3, 5), 'quality' => random_int(3, 5), 'price_rating' => random_int(3, 5),
                'comment' => 'خدمة جيدة وفي الوقت المحدد.', 'price_anomaly_flag' => false,
            ]);
        }

        // ---- Withdrawals (money out) ----
        $wStatuses = [WithdrawalStatus::Processing, WithdrawalStatus::Processing, WithdrawalStatus::Completed, WithdrawalStatus::Rejected];
        foreach ($wStatuses as $i => $st) {
            $t = $active[$i % count($active)];
            Withdrawal::create([
                'technician_id' => $t->id, 'amount' => number_format(100 + $i * 50, 2, '.', ''),
                'destination_details' => $t->sham_cash_number ?? '1234567890123456',
                'destination_name' => $t->sham_cash_name ?? 'الفني',
                'status' => $st,
                'receipt_url' => $st === WithdrawalStatus::Completed ? 'withdrawal-receipts/demo.jpg' : null,
                'processed_by' => in_array($st, [WithdrawalStatus::Completed, WithdrawalStatus::Rejected], true) ? $admin?->id : null,
            ]);
        }

        // ---- Deposits / top-ups (money in) ----
        $dStatuses = [TopUpStatus::Pending, TopUpStatus::Pending, TopUpStatus::Pending, TopUpStatus::Succeeded, TopUpStatus::Succeeded, TopUpStatus::Rejected];
        foreach ($dStatuses as $i => $st) {
            $w = $clients[$i % count($clients)]->wallet()->firstOrFail();
            TopUp::create([
                'wallet_id' => $w->id, 'amount' => number_format(50 + $i * 25, 2, '.', ''),
                'gateway_reference' => 'DEMO-TX-'.(1000 + $i),
                'receipt_url' => 'top-ups/demo.jpg',
                'status' => $st,
                'reviewed_by' => $st === TopUpStatus::Pending ? null : $admin?->id,
            ]);
        }

        // ---- Disputes ----
        $disputed = Order::where('status', OrderStatus::Disputed)->first() ?? ($completed[0] ?? null);
        if ($disputed) {
            Dispute::create(['order_id' => $disputed->id, 'raised_by' => $disputed->client_id, 'reason' => DisputeReason::FaultReturned, 'description' => 'العطل رجع بعد يومين.', 'status' => DisputeStatus::Open]);
        }
        if (isset($completed[1])) {
            Dispute::create(['order_id' => $completed[1]->id, 'raised_by' => $completed[1]->client_id, 'reason' => DisputeReason::HomeDamage, 'description' => 'ضرر بسيط بالجدار.', 'status' => DisputeStatus::UnderReview]);
        }
        if (isset($completed[2])) {
            Dispute::create(['order_id' => $completed[2]->id, 'raised_by' => $completed[2]->client_id, 'reason' => DisputeReason::Other, 'description' => 'تم الحل بالاسترداد الجزئي.', 'status' => DisputeStatus::Resolved, 'resolution' => DisputeResolution::PartialRefund, 'resolved_by' => $admin?->id, 'resolved_at' => now()->subDay()]);
        }

        // ---- Technician flags ----
        $flagReasons = [TechnicianFlagReason::NoShow, TechnicianFlagReason::Withdrawal, TechnicianFlagReason::PartsDelay];
        foreach ($flagReasons as $reason) {
            TechnicianFlag::create(['technician_id' => $active[array_rand($active)]->id, 'reason' => $reason, 'status' => TechnicianFlagStatus::Open]);
        }
        TechnicianFlag::create(['technician_id' => $active[0]->id, 'reason' => TechnicianFlagReason::PartsDelay, 'status' => TechnicianFlagStatus::Reviewed, 'outcome' => TechnicianFlagOutcome::Dismissed, 'note' => 'حالة مبررة.', 'reviewed_by' => $admin?->id, 'reviewed_at' => now()->subDays(2)]);

        $this->command->info('DemoSeeder: '.count($clients).' clients, '.(count($active) + 6).' technicians, '.count($plan).' orders + related money/moderation records created.');
    }

    private function wallet(User $user, string $available): void
    {
        $w = $user->wallet()->firstOrNew(['user_id' => $user->id]);
        $w->fill(['user_id' => $user->id]);
        $w->save();
        $w->available_balance = $available;
        $w->held_balance = '0.00';
        $w->save();
    }
}
