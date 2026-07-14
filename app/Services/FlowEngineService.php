<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadType;
use App\Models\WhatsappConversation;
use App\Models\WhatsappNumber;

/**
 * WhatsApp lead-capture chatbot — a finite-state machine ported from the
 * MB Leads FlowEngine. Walks a new contact through: choose service → name →
 * business → email, then creates a lead (source: whatsapp) and pings the
 * configured alert numbers.
 */
class FlowEngineService
{
    public function __construct(private WhatsAppService $wa) {}

    private const SERVICES = [
        '1' => 'Free Spotlight',
        '2' => 'Go Viral',
        '3' => 'Branding Consultation',
        '4' => 'Automation',
        '5' => 'Package Enquiry',
    ];

    /** Entry point for an inbound message. Returns the reply text. */
    public function handleIncoming(string $phone, string $text): string
    {
        $phone = preg_replace('/\D+/', '', $phone);
        $text = trim($text);
        $this->wa->log($phone, 'in', $text);

        $convo = WhatsappConversation::firstOrCreate(['phone' => $phone], ['state' => 'new', 'data' => []]);
        $convo->last_activity_at = now();

        $reply = $this->advance($convo, $text);

        $convo->save();
        $this->wa->sendText($phone, $reply);

        return $reply;
    }

    private function advance(WhatsappConversation $c, string $text): string
    {
        $data = $c->data ?? [];
        $lower = strtolower($text);

        // Global restart / greeting.
        if (in_array($lower, ['hi', 'hello', 'start', 'menu', 'hai'], true) || $c->state === 'new' || $c->state === 'done') {
            $c->state = 'await_service';
            $c->data = [];

            return $this->welcome();
        }

        switch ($c->state) {
            case 'await_service':
                if (! isset(self::SERVICES[$text])) {
                    return "Please reply with a number 1-5.\n\n".$this->menu();
                }
                $data['service'] = self::SERVICES[$text];
                $c->data = $data;
                $c->state = 'await_name';

                return "Great choice — *{$data['service']}*! 🎉\n\nWhat's your name?";

            case 'await_name':
                $data['name'] = $text;
                $c->data = $data;
                $c->state = 'await_business';

                return "Thanks {$text}! What's your business name?";

            case 'await_business':
                $data['business'] = $text;
                $c->data = $data;
                $c->state = 'await_email';

                return 'Almost done — what email should we use to reach you? (or type "skip")';

            case 'await_email':
                $data['email'] = $lower === 'skip' ? null : $text;
                $c->data = $data;
                $lead = $this->createLead($c);
                $c->lead_id = $lead->id;
                $c->state = 'done';
                $this->notifyAlerts($lead, $data);

                return "You're all set, {$data['name']}! ✅\n\nOur team will reach out about your *{$data['service']}* enquiry shortly. Type *menu* anytime to start over.";
        }

        $c->state = 'await_service';

        return $this->welcome();
    }

    private function welcome(): string
    {
        return "👋 Welcome to *Malayznbeat*!\n\nWhich service are you interested in?\n\n".$this->menu();
    }

    private function menu(): string
    {
        return collect(self::SERVICES)->map(fn ($name, $k) => "{$k}. {$name}")->implode("\n");
    }

    private function createLead(WhatsappConversation $c): Lead
    {
        $data = $c->data;
        $phone = $c->phone;

        $contact = Contact::where('phone_normalized', $phone)->first()
            ?? Contact::create([
                'business_name' => $data['business'] ?? ($data['name'] ?? 'WhatsApp lead'),
                'contact_person' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $phone,
                'source' => 'whatsapp',
            ]);

        $typeId = ! empty($data['service']) ? LeadType::firstOrCreate(['name' => $data['service']])->id : null;

        return Lead::create([
            'contact_id' => $contact->id,
            'lead_type_id' => $typeId,
            'title' => $data['service'] ?? 'WhatsApp enquiry',
            'pipeline_stage' => 'intake',
            'status' => 'active',
            'source' => 'whatsapp',
            'last_activity_at' => now(),
            'meta' => ['channel' => 'whatsapp', 'captured' => $data],
        ]);
    }

    private function notifyAlerts(Lead $lead, array $data): void
    {
        $msg = "🔔 New WhatsApp lead!\n{$data['name']} — {$data['business']}\nService: {$data['service']}\nPhone: {$lead->contact->phone}";
        foreach (WhatsappNumber::where('is_active', true)->pluck('phone') as $to) {
            $this->wa->sendText($to, $msg);
        }
    }
}
