<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppSettingsController extends Controller
{
    public function __construct(private WhatsAppService $wa) {}

    public function show()
    {
        return response()->json([
            'configured' => $this->wa->isConfigured(),
            'phone_number_id' => Setting::get('whatsapp.phone_number_id'),
            'waba_id' => Setting::get('whatsapp.waba_id'),
            'verify_token' => $this->wa->verifyToken(),
            'api_version' => Setting::get('whatsapp.api_version', 'v21.0'),
            // secrets are write-only — report only whether they're set
            'has_access_token' => (bool) Setting::get('whatsapp.access_token'),
            'has_app_secret' => (bool) Setting::get('whatsapp.app_secret'),
            'webhook_url' => url('/api/v1/whatsapp/webhook'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'phone_number_id' => ['nullable', 'string', 'max:60'],
            'waba_id' => ['nullable', 'string', 'max:60'],
            'verify_token' => ['nullable', 'string', 'max:120'],
            'api_version' => ['nullable', 'string', 'max:10'],
            'access_token' => ['nullable', 'string'],
            'app_secret' => ['nullable', 'string'],
        ]);

        foreach (['phone_number_id', 'waba_id', 'verify_token', 'api_version'] as $k) {
            if (array_key_exists($k, $data)) {
                Setting::put("whatsapp.$k", $data[$k]);
            }
        }
        // Only overwrite secrets when a non-empty value is supplied.
        foreach (['access_token', 'app_secret'] as $k) {
            if (! empty($data[$k])) {
                Setting::put("whatsapp.$k", $data[$k], encrypt: true);
            }
        }

        return $this->show();
    }

    /** Send a test message to verify the connection. */
    public function test(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $res = $this->wa->sendText($data['phone'], 'Test message from Malayznbeat CRM ✅');

        return response()->json($res);
    }
}
