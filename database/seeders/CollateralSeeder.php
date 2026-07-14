<?php

namespace Database\Seeders;

use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Database\Seeder;

class CollateralSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@malayznbeat.com')->value('id');

        $items = [
            ['website', 'Nasi Kandar Pelita — Online Ordering', 'https://pelita.example.my', ['Login' => 'admin', 'Password' => '••••••']],
            ['website', 'Sunway Property Landing Page', 'https://sunway.example.my', null],
            ['video', 'Glow Beauty — Brand Reel', 'https://youtu.be/example1', null],
            ['video', 'Kopitiam Heritage — Story', 'https://youtu.be/example2', null],
            ['graphic', 'Ramadan Campaign — Poster Set', null, null],
            ['graphic', 'Elite Fitness — Instagram Grid', null, null],
            ['automation', 'AutoCare — WhatsApp Booking Bot', 'https://autocare.example.my', null],
            ['article', 'Why Every SME Needs a Blog', 'https://malayznbeat.com/blog/sme-blog', null],
        ];

        foreach ($items as $i => [$type, $title, $url, $creds]) {
            PortfolioItem::updateOrCreate(['title' => $title], [
                'type' => $type, 'url' => $url, 'credentials' => $creds,
                'description' => 'Delivered by the Malayznbeat team.', 'sort_order' => $i, 'created_by' => $admin,
            ]);
        }
    }
}
