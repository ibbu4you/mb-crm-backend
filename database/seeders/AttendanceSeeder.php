<?php

namespace Database\Seeders;

use App\Models\OfficeLocation;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    public function run(): void
    {
        Setting::put('attendance.work_start_time', '09:00');
        Setting::put('attendance.late_grace_minutes', 15);
        Setting::put('attendance.half_day_minutes', 240);

        OfficeLocation::updateOrCreate(
            ['name' => 'Malayznbeat HQ — Kuala Lumpur'],
            ['lat' => 3.1578000, 'lng' => 101.7123000, 'radius_m' => 200, 'is_active' => true],
        );
    }
}
