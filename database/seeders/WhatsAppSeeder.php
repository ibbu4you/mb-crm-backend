<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\WhatsappNumber;
use Illuminate\Database\Seeder;

class WhatsAppSeeder extends Seeder
{
    public function run(): void
    {
        Setting::put('whatsapp.verify_token', 'malayznbeat-verify');
        Setting::put('whatsapp.api_version', 'v21.0');

        $numbers = [
            ['label' => 'Sales alerts', 'phone' => '60123456789'],
            ['label' => 'Admin alerts', 'phone' => '60198887766'],
        ];
        foreach ($numbers as $n) {
            WhatsappNumber::updateOrCreate(['phone' => $n['phone']], $n);
        }
    }
}
