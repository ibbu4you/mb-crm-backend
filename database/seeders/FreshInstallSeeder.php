<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal bootstrap for a clean install — the structural essentials only, with
 * NO demo/business data. Seeds roles + permissions, a single Administrator
 * account, and sensible default settings. Used to reset the platform before
 * importing real data.
 */
class FreshInstallSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => 'admin@malayznbeat.com'],
            ['name' => 'Administrator', 'username' => 'admin', 'is_active' => true, 'password' => Hash::make('password')],
        );
        $admin->syncRoles([Roles::SUPER_ADMIN]);

        $settings = [
            'attendance.work_start_time' => '09:00',
            'attendance.late_grace_minutes' => '15',
            'attendance.half_day_minutes' => '240',
            'leave.annual_days' => '14',
            'leave.sick_days' => '14',
            'whatsapp.verify_token' => 'malayznbeat-verify',
            'whatsapp.api_version' => 'v21.0',
        ];
        foreach ($settings as $key => $value) {
            Setting::put($key, $value);
        }
    }
}
