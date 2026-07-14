<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business (Meta Cloud API) integration.
 *
 * Configuration lives in the encrypted `settings` store (or .env fallback) so
 * an admin can wire it from the Settings screen. When no credentials are
 * present, outbound sends are logged (not thrown) so the whole chatbot flow
 * keeps working end-to-end in development.
 */
class WhatsAppService
{
    public function cfg(string $key, $default = null)
    {
        return Setting::get("whatsapp.$key", env(strtoupper('WHATSAPP_'.$key), $default));
    }

    public function isConfigured(): bool
    {
        return (bool) $this->cfg('phone_number_id') && (bool) $this->cfg('access_token');
    }

    public function verifyToken(): ?string
    {
        return $this->cfg('verify_token', 'malayznbeat-verify');
    }

    /** Verify the X-Hub-Signature-256 header against the app secret. */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->cfg('app_secret');
        if (! $secret) {
            return true; // no secret configured -> accept (dev)
        }
        if (! $signature) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /** Send a plain text WhatsApp message. */
    public function sendText(string $phone, string $text): array
    {
        $phone = preg_replace('/\D+/', '', $phone);

        if (! $this->isConfigured()) {
            $this->log($phone, 'out', $text, ['note' => 'not_configured'], 'not_configured');
            Log::info("[WhatsApp:mock] to {$phone}: {$text}");

            return ['ok' => false, 'mock' => true];
        }

        $version = $this->cfg('api_version', 'v21.0');
        $url = "https://graph.facebook.com/{$version}/{$this->cfg('phone_number_id')}/messages";

        try {
            $res = Http::withToken($this->cfg('access_token'))
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ]);
            $this->log($phone, 'out', $text, $res->json(), $res->successful() ? 'sent' : 'failed');

            return ['ok' => $res->successful(), 'response' => $res->json()];
        } catch (\Throwable $e) {
            $this->log($phone, 'out', $text, ['error' => $e->getMessage()], 'error');

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function log(string $phone, string $direction, ?string $body, ?array $payload = null, ?string $status = null): void
    {
        WhatsappMessage::create(compact('phone', 'direction', 'body', 'payload', 'status'));
    }
}
