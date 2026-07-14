<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappGroup;
use App\Models\WhatsappGroupMember;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class WhatsAppModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->grantAccess();

        $admin = User::first();

        // Templates
        $templates = [
            ['name' => 'Welcome Message', 'category' => 'utility', 'header' => 'Welcome to Malayznbeat! 🎉',
                'body' => "Hi {{1}}, thanks for reaching out! Our team will get back to you shortly. How can we help you today?",
                'footer' => 'Malayznbeat CRM'],
            ['name' => 'Promo — Go Viral', 'category' => 'marketing', 'header' => '🚀 Go Viral This Month',
                'body' => "Hi {{1}}, ready to blow up on socials? Our Go-Viral package is 20% off this week only. Reply YES to claim.",
                'footer' => 'Reply STOP to opt out',
                'buttons' => [['type' => 'quick_reply', 'text' => 'Claim offer', 'value' => 'CLAIM'], ['type' => 'url', 'text' => 'See packages', 'value' => 'https://malayznbeat.com']]],
            ['name' => 'Appointment Reminder', 'category' => 'utility',
                'body' => "Hi {{1}}, this is a reminder for your consultation with Malayznbeat tomorrow. See you soon!"],
        ];
        foreach ($templates as $t) {
            WhatsappTemplate::firstOrCreate(['name' => $t['name']], array_merge($t, ['created_by' => $admin?->id, 'status' => 'approved']));
        }

        // Audience group seeded from existing contacts with phones
        $group = WhatsappGroup::firstOrCreate(['name' => 'All Leads'], [
            'description' => 'Everyone who has enquired', 'color' => '#25D366', 'created_by' => $admin?->id,
        ]);
        Contact::whereNotNull('phone')->where('phone', '!=', '')->limit(25)->get()->each(function (Contact $c) use ($group) {
            $phone = preg_replace('/\D+/', '', (string) $c->phone);
            if ($phone) {
                WhatsappGroupMember::firstOrCreate(['group_id' => $group->id, 'phone' => $phone], [
                    'name' => $c->business_name ?? $c->contact_person, 'contact_id' => $c->id,
                ]);
            }
        });

        // A completed sample campaign
        if (! WhatsappCampaign::where('name', 'March Go-Viral Blast')->exists()) {
            $tpl = WhatsappTemplate::where('name', 'Promo — Go Viral')->first();
            $count = $group->members()->count();
            WhatsappCampaign::create([
                'name' => 'March Go-Viral Blast', 'template_id' => $tpl?->id, 'group_id' => $group->id,
                'message' => $tpl?->body ?? 'Hi {{1}}, check out our latest offer!',
                'status' => 'sent', 'sent_at' => now()->subDays(2),
                'total_recipients' => $count, 'sent_count' => $count, 'failed_count' => 0,
                'created_by' => $admin?->id,
            ]);
        }

        // A couple of inbound sample messages so the inbox isn't empty
        $samplePhone = WhatsappGroupMember::value('phone') ?? '60123456789';
        if (WhatsappMessage::where('phone', $samplePhone)->count() === 0) {
            WhatsappMessage::create(['phone' => $samplePhone, 'direction' => 'in', 'body' => 'Hi, is the Go-Viral package still available?', 'status' => 'received', 'created_at' => now()->subMinutes(30)]);
            WhatsappMessage::create(['phone' => $samplePhone, 'direction' => 'out', 'body' => 'Yes! It is 20% off this week. Want me to send the details?', 'status' => 'sent', 'created_at' => now()->subMinutes(25)]);
            WhatsappMessage::create(['phone' => $samplePhone, 'direction' => 'in', 'body' => 'Yes please 🙌', 'status' => 'received', 'created_at' => now()->subMinutes(5)]);
        }
    }

    private function grantAccess(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $perms = ['whatsapp.view', 'whatsapp.send', 'whatsapp.manage'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach ([Roles::SUPER_ADMIN, 'Admin', 'Manager'] as $roleName) {
            if ($role = Role::where('name', $roleName)->first()) {
                $role->givePermissionTo($perms); // additive — preserves existing grants
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
