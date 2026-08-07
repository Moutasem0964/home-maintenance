<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Seeder;

/** Fake company offices (no real company yet) for the technician approval visit. */
class OfficeSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            ['name' => 'مكتب المزة', 'address' => 'مزة شرقية، شارع الفارابي', 'phone' => '0912345678'],
            ['name' => 'مكتب المالكي', 'address' => 'المالكي، شارع الجلاء', 'phone' => '0933444555'],
            ['name' => 'مكتب برزة', 'address' => 'برزة، الساحة الرئيسية', 'phone' => '0955666777'],
            ['name' => 'مكتب المهاجرين', 'address' => 'المهاجرين، شارع الشيخ محي الدين', 'phone' => '0988111222'],
        ];

        foreach ($offices as $office) {
            Office::updateOrCreate(['name' => $office['name']], $office + ['is_active' => true]);
        }
    }
}
