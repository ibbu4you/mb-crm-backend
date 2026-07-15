<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppInboxController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    /** Conversation list: newest activity first, with unread + last-message preview. */
    public function threads(Request $request)
    {
        $rows = WhatsappMessage::query()
            ->selectRaw('phone, MAX(id) as last_id, MAX(created_at) as last_at, COUNT(*) as total')
            ->groupBy('phone')
            ->orderByDesc('last_at')
            ->limit(100)
            ->get();

        $phones = $rows->pluck('phone');
        $lastMsgs = WhatsappMessage::whereIn('id', $rows->pluck('last_id'))->get()->keyBy('phone');
        $lastInbound = WhatsappMessage::where('direction', 'in')->whereIn('phone', $phones)
            ->selectRaw('phone, MAX(created_at) as t')->groupBy('phone')->pluck('t', 'phone');
        $convos = WhatsappConversation::whereIn('phone', $phones)->get()->keyBy('phone');
        $contacts = Contact::whereIn('phone_normalized', $phones->map(fn ($p) => Contact::normalizePhone($p)))->get();

        $threads = $rows->map(function ($r) use ($lastMsgs, $lastInbound, $convos, $contacts) {
            $convo = $convos->get($r->phone);
            $inboundAt = $lastInbound->get($r->phone);
            $readAt = $convo?->agent_read_at;
            $unread = $inboundAt && (! $readAt || $inboundAt > $readAt);
            $contact = $contacts->firstWhere('phone_normalized', Contact::normalizePhone($r->phone));

            return [
                'phone' => $r->phone,
                'name' => $convo?->contact_name ?? $contact?->business_name ?? $contact?->contact_person,
                'last_message' => $lastMsgs->get($r->phone)?->body,
                'last_direction' => $lastMsgs->get($r->phone)?->direction,
                'last_at' => optional($lastMsgs->get($r->phone)?->created_at)->toIso8601String(),
                'total' => (int) $r->total,
                'unread' => (bool) $unread,
                'lead_id' => $convo?->lead_id,
            ];
        });

        return response()->json([
            'data' => $threads->values(),
            'connected' => $this->wa->isConfigured(),
        ]);
    }

    public function show(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);
        $messages = WhatsappMessage::where('phone', $phone)->orderBy('id')->limit(300)->get()
            ->map(fn (WhatsappMessage $m) => [
                'id' => $m->id,
                'direction' => $m->direction,
                'body' => $m->body,
                'status' => $m->status,
                'media_type' => $m->media_type,
                'media_url' => $m->media_url,
                'media_name' => $m->media_name,
                'at' => $m->created_at->toIso8601String(),
            ]);
        $convo = WhatsappConversation::where('phone', $phone)->first();
        $contact = Contact::where('phone_normalized', Contact::normalizePhone($phone))->first();

        // Reading a thread clears its unread state.
        if ($convo) {
            $convo->update(['agent_read_at' => now()]);
        }

        return response()->json([
            'phone' => $phone,
            'name' => $convo?->contact_name ?? $contact?->business_name ?? $contact?->contact_person,
            'lead_id' => $convo?->lead_id,
            'contact_id' => $contact?->id,
            'messages' => $messages,
        ]);
    }

    public function reply(Request $request, string $phone)
    {
        $data = $request->validate([
            // body is optional when an attachment is present (it becomes the caption)
            'body' => ['nullable', 'required_without:file', 'string', 'max:4096'],
            'file' => ['nullable', 'file', 'max:16384', 'mimes:jpg,jpeg,png,webp,gif,mp4,3gp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt'],
        ]);
        $phone = preg_replace('/\D+/', '', $phone);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $type = $this->mediaType($file->getMimeType());
            // Meta fetches the link, so it has to be publicly reachable
            $url = asset('storage/'.$file->store('whatsapp', 'public'));
            $result = $this->wa->sendMedia($phone, $type, $url, $data['body'] ?? null, $file->getClientOriginalName());
        } else {
            $result = $this->wa->sendText($phone, $data['body']);
        }

        WhatsappConversation::updateOrCreate(
            ['phone' => $phone],
            ['last_activity_at' => now(), 'agent_read_at' => now()],
        );

        return response()->json([
            'ok' => (bool) ($result['ok'] ?? false),
            'mock' => (bool) ($result['mock'] ?? false),
        ], 201);
    }

    /**
     * Remove a message from the CRM log. WhatsApp has no delete-for-recipient in
     * the Cloud API, so this only clears our record — the recipient keeps theirs.
     */
    public function destroyMessage(WhatsappMessage $message)
    {
        $message->delete();

        return response()->json(['deleted' => true]);
    }

    /** Map an uploaded file's mime to the type Meta expects. */
    private function mediaType(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'video/') => 'video',
            default => 'document',
        };
    }

    public function markRead(string $phone)
    {
        $phone = preg_replace('/\D+/', '', $phone);
        WhatsappConversation::updateOrCreate(['phone' => $phone], ['agent_read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
