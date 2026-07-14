<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FlowEngineService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

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

    /** Inbound messages (POST). Accepts the Meta Cloud payload or a simple {phone,message} test body. */
    public function handle(Request $request)
    {
        if (! $this->wa->verifySignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $messages = $this->extractMessages($request->all());
        foreach ($messages as [$from, $text]) {
            if ($from && $text !== null) {
                $this->flow->handleIncoming($from, $text);
            }
        }

        return response()->json(['received' => count($messages)]);
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
