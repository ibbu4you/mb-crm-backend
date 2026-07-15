<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function log(string $phone, string $direction, ?string $body, ?array $payload = null, ?string $status = null, array $media = []): void
    {
        WhatsappMessage::create(compact('phone', 'direction', 'body', 'payload', 'status') + $media);
    }

    /**
     * Send an image / video / document by public link — Meta fetches the URL, so
     * it must be reachable from the internet (our public storage disk is).
     *
     * @param  'image'|'video'|'document'  $type
     */
    public function sendMedia(string $phone, string $type, string $url, ?string $caption = null, ?string $filename = null): array
    {
        $phone = preg_replace('/\D+/', '', $phone);
        $media = ['link' => $url];
        if ($caption && $type !== 'document') {
            $media['caption'] = $caption;
        }
        if ($type === 'document') {
            $media['filename'] = $filename ?: 'file';
            if ($caption) {
                $media['caption'] = $caption;
            }
        }
        $meta = ['media_type' => $type, 'media_url' => $url, 'media_name' => $filename];

        if (! $this->isConfigured()) {
            $this->log($phone, 'out', $caption, ['note' => 'not_configured'], 'not_configured', $meta);

            return ['ok' => false, 'mock' => true];
        }

        $version = $this->cfg('api_version', 'v21.0');
        $endpoint = "https://graph.facebook.com/{$version}/{$this->cfg('phone_number_id')}/messages";

        try {
            $res = Http::withToken($this->cfg('access_token'))->post($endpoint, [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => $type,
                $type => $media,
            ]);
            $this->log($phone, 'out', $caption, $res->json(), $res->successful() ? 'sent' : 'failed', $meta);

            return ['ok' => $res->successful(), 'response' => $res->json()];
        } catch (\Throwable $e) {
            $this->log($phone, 'out', $caption, ['error' => $e->getMessage()], 'error', $meta);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ---- Message templates (Meta is the source of truth; we mirror them read-only) ----

    /**
     * Pull the WhatsApp Business Account's templates from Meta and mirror them
     * locally. Matches on name+language (Meta's own unique key), so re-syncing
     * updates rather than duplicates.
     *
     * @return array{ok:bool, synced?:int, error?:string}
     */
    public function syncTemplates(?int $userId = null): array
    {
        $waba = $this->cfg('waba_id');
        $token = $this->cfg('access_token');

        if (! $waba) {
            return ['ok' => false, 'error' => 'Add your WhatsApp Business Account ID in Settings first.'];
        }
        if (! $token) {
            return ['ok' => false, 'error' => 'Add your access token in Settings first.'];
        }

        $version = $this->cfg('api_version', 'v21.0');

        try {
            $res = Http::withToken($token)
                ->get("https://graph.facebook.com/{$version}/{$waba}/message_templates", ['limit' => 200]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->json('error.message') ?? 'Meta rejected the request.'];
        }

        $synced = 0;
        foreach ($res->json('data', []) as $remote) {
            if (empty($remote['name'])) {
                continue;
            }
            $row = $this->mapTemplate($remote);
            $tpl = WhatsappTemplate::firstOrNew(['name' => $row['name'], 'language' => $row['language']]);
            $tpl->fill($row);
            $tpl->created_by ??= $userId;   // keep the original author on re-sync
            $tpl->save();
            $synced++;
        }

        return ['ok' => true, 'synced' => $synced];
    }

    /** Meta's template shape -> our columns. */
    private function mapTemplate(array $t): array
    {
        $components = collect($t['components'] ?? []);
        $part = fn (string $type) => $components->firstWhere('type', $type);

        $header = $part('HEADER');
        $headerText = match (true) {
            ! $header => null,
            ($header['format'] ?? 'TEXT') === 'TEXT' => $header['text'] ?? null,
            // media headers have no text — note the type so it isn't silently lost
            default => '['.strtolower($header['format']).' header]',
        };

        $buttons = collect($part('BUTTONS')['buttons'] ?? [])->map(fn ($b) => [
            'type' => match ($b['type'] ?? '') {
                'URL' => 'url',
                'PHONE_NUMBER' => 'phone',
                default => 'quick_reply',
            },
            'text' => Str::limit((string) ($b['text'] ?? ''), 40, ''),
            'value' => $b['url'] ?? $b['phone_number'] ?? null,
        ])->take(3)->values()->all();

        $category = strtolower((string) ($t['category'] ?? 'marketing'));

        return [
            'name' => $t['name'],
            'language' => $t['language'] ?? 'en',
            'category' => in_array($category, ['marketing', 'utility', 'authentication'], true) ? $category : 'marketing',
            'header' => $headerText ? Str::limit($headerText, 190, '') : null,
            'body' => $part('BODY')['text'] ?? '',
            'footer' => isset($part('FOOTER')['text']) ? Str::limit($part('FOOTER')['text'], 190, '') : null,
            'buttons' => $buttons,
            'status' => match (strtoupper((string) ($t['status'] ?? ''))) {
                'APPROVED' => 'approved',
                'REJECTED' => 'rejected',
                'PENDING', 'IN_APPEAL', 'PENDING_DELETION' => 'pending',
                default => 'draft',
            },
        ];
    }
}
