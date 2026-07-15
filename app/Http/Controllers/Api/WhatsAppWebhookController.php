<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlowEngineService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private WhatsAppService $wa, private FlowEngineService $flow) {}

    /** Meta webhook verification handshake (GET). */
    public function verify(Request $request)
    {
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === $this->wa->verifyToken()) {
            return response($request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Inbound messages (POST). Accepts the Meta Cloud payload or a simple
     * {phone,message} test body.
     *
     * Every outcome is logged: a dropped delivery here is otherwise completely
     * invisible (Meta retries quietly and nothing reaches the inbox), which makes
     * "replies aren't arriving" impossible to diagnose. Grep laravel.log for
     * [WhatsApp] to see exactly which branch you're hitting.
     */
    public function handle(Request $request)
    {
        if (! $this->wa->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('[WhatsApp] webhook REJECTED — signature mismatch. The app_secret in Settings does not match the Meta app.', [
                'signature_sent' => (bool) $request->header('X-Hub-Signature-256'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $messages = $this->extractMessages($request->all());

        if (! $messages) {
            // Status callbacks (sent/delivered/read) legitimately land here; so do
            // media-only replies, which extractMessages cannot read yet.
            Log::info('[WhatsApp] webhook OK but no readable message', [
                'fields' => $this->payloadShape($request->all()),
            ]);

            return response()->json(['received' => 0]);
        }

        foreach ($messages as [$from, $text]) {
            if ($from && $text !== null) {
                Log::info('[WhatsApp] inbound message', ['from' => $from]);
                $this->flow->handleIncoming($from, $text);
            }
        }

        return response()->json(['received' => count($messages)]);
    }

    /** Which change-fields Meta actually sent — tells statuses apart from messages. */
    private function payloadShape(array $payload): array
    {
        $shape = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $shape[] = [
                    'field' => $change['field'] ?? '?',
                    'has_messages' => isset($value['messages']),
                    'has_statuses' => isset($value['statuses']),
                    'message_type' => $value['messages'][0]['type'] ?? null,
                ];
            }
        }

        return $shape ?: ['raw_keys' => array_keys($payload)];
    }

    /** @return array<int, array{0:?string,1:?string}> */
    private function extractMessages(array $payload): array
    {
        // Simple test / vendor format: {"phone": "...", "message": "..."}
        if (isset($payload['phone']) && isset($payload['message'])) {
            return [[$payload['phone'], $payload['message']]];
        }

        // Meta Cloud API format
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $m) {
                    $from = $m['from'] ?? null;
                    $text = $m['text']['body']
                        ?? $m['button']['text']
                        ?? $m['interactive']['button_reply']['title']
                        ?? $m['interactive']['list_reply']['title']
                        ?? null;
                    $out[] = [$from, $text];
                }
            }
        }

        return $out;
    }
}
