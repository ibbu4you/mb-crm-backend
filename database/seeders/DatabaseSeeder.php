<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $admin = User::updateOrCreate(
            ['email' => 'admin@malayznbeat.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'phone' => null,
                'is_active' => true,
                'password' => Hash::make('password'),
            ],
        );
        $admin->syncRoles([Roles::SUPER_ADMIN]);

        // A couple of demo employees so the Employees screen isn't empty.
        $sales = User::updateOrCreate(
            ['email' => 'sales@malayznbeat.com'],
            ['name' => 'Daniel Tan', 'is_active' => true, 'password' => Hash::make('password')],
        );
        $sales->syncRoles(['Salesperson']);

        $writer = User::updateOrCreate(
            ['email' => 'writer@malayznbeat.com'],
            ['name' => 'Nurul Huda', 'is_active' => true, 'password' => Hash::make('password')],
        );
        $writer->syncRoles(['Tech Writer']);

        $this->call(CrmSeeder::class);
        $this->call(FieldSalesSeeder::class);
        $this->call(ContentSeeder::class);
        $this->call(ViralSeeder::class);
        $this->call(SupportSeeder::class);
        $this->call(InvoicingSeeder::class);
        $this->call(TaskSeeder::class);
        $this->call(WhatsAppSeeder::class);
        $this->call(CollateralSeeder::class);
        $this->call(AttendanceSeeder::class);
    }
}
