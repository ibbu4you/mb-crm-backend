<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappCampaign;
use App\Models\WhatsappCampaignRecipient;
use App\Models\WhatsappGroup;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppCampaignController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function index()
    {
        $campaigns = WhatsappCampaign::with(['template', 'group'])->latest()->get()
            ->map(fn (WhatsappCampaign $c) => $this->row($c));

        return response()->json(['data' => $campaigns, 'connected' => $this->wa->isConfigured()]);
    }

    public function show(WhatsappCampaign $campaign)
    {
        $campaign->load(['template', 'group', 'recipients' => fn ($q) => $q->orderBy('id')]);
        $row = $this->row($campaign);
        $row['recipients'] = $campaign->recipients->map(fn (WhatsappCampaignRecipient $r) => [
            'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'status' => $r->status,
            'error' => $r->error, 'sent_at' => optional($r->sent_at)->toIso8601String(),
        ]);
        $row['message'] = $campaign->message;

        return response()->json(['data' => $row]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'draft';

        $campaign = WhatsappCampaign::create($data);
        $this->syncRecipients($campaign);

        return response()->json(['data' => $this->row($campaign->fresh(['template', 'group']))], 201);
    }

    public function update(Request $request, WhatsappCampaign $campaign)
    {
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'A sent campaign cannot be edited.');
        $data = $this->validateData($request);
        $groupChanged = ($data['group_id'] ?? null) !== $campaign->group_id;
        $campaign->update($data);
        if ($groupChanged) {
            $this->syncRecipients($campaign);
        }

        return response()->json(['data' => $this->row($campaign->fresh(['template', 'group']))]);
    }

    public function destroy(WhatsappCampaign $campaign)
    {
        $campaign->delete();

        return response()->json(['message' => 'Campaign deleted.']);
    }

    /** Fire the broadcast now: send to every pending recipient and record status. */
    public function send(WhatsappCampaign $campaign)
    {
        abort_if(in_array($campaign->status, ['sending', 'sent'], true), 422, 'This campaign has already been sent.');
        abort_if($campaign->recipients()->count() === 0, 422, 'This campaign has no recipients.');

        $campaign->update(['status' => 'sending']);
        $sent = 0;
        $failed = 0;

        foreach ($campaign->recipients()->where('status', 'pending')->cursor() as $recipient) {
            $result = $this->wa->sendText($recipient->phone, $this->render($campaign->message, $recipient->name));
            $ok = ($result['ok'] ?? false) || ($result['mock'] ?? false); // mock = logged (dev), still counts as sent
            $recipient->update([
                'status' => $ok ? 'sent' : 'failed',
                'error' => $ok ? null : ($result['error'] ?? 'send_failed'),
                'sent_at' => now(),
            ]);
            $ok ? $sent++ : $failed++;
        }

        $campaign->update([
            'status' => $failed && ! $sent ? 'failed' : 'sent',
            'sent_at' => now(),
            'sent_count' => $campaign->recipients()->where('status', 'sent')->count(),
            'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
        ]);

        return response()->json(['data' => $this->row($campaign->fresh(['template', 'group'])), 'sent' => $sent, 'failed' => $failed]);
    }

    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'template_id' => ['nullable', 'exists:whatsapp_templates,id'],
            'group_id' => ['required', 'exists:whatsapp_groups,id'],
            'message' => ['required', 'string', 'max:4096'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        return $data;
    }

    private function syncRecipients(WhatsappCampaign $campaign): void
    {
        $campaign->recipients()->delete();
        $group = WhatsappGroup::with('members')->find($campaign->group_id);
        $rows = ($group?->members ?? collect())->map(fn ($m) => [
            'campaign_id' => $campaign->id, 'name' => $m->name, 'phone' => $m->phone,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ])->all();
        if ($rows) {
            WhatsappCampaignRecipient::insert($rows);
        }
        $campaign->update(['total_recipients' => count($rows)]);
    }

    private function render(string $message, ?string $name): string
    {
        return str_ireplace(['{{name}}', '{name}', '{{1}}'], $name ?: 'there', $message);
    }

    private function row(WhatsappCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'template_id' => $c->template_id,
            'template' => $c->template?->name,
            'group_id' => $c->group_id,
            'group' => $c->group?->name,
            'status' => $c->status,
            'scheduled_at' => optional($c->scheduled_at)->toIso8601String(),
            'sent_at' => optional($c->sent_at)->toIso8601String(),
            'total_recipients' => $c->total_recipients,
            'sent_count' => $c->sent_count,
            'failed_count' => $c->failed_count,
            'created_at' => $c->created_at->toIso8601String(),
        ];
    }
}
