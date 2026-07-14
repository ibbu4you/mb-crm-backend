<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class AppSettingsController extends Controller
{
    public function show()
    {
        $logo = Setting::get('app.logo');

        return response()->json([
            'company_name' => Setting::get('app.company_name', 'Malayznbeat'),
            'tagline' => Setting::get('app.tagline', 'Content & Growth Agency'),
            'logo_url' => $logo ? asset('storage/'.$logo) : null,
            'stuck_threshold_days' => (int) Setting::get('content.stuck_threshold_days', 3),
            'dormant_days' => (int) Setting::get('leads.dormant_days', 30),
            'tax_rate' => (float) Setting::get('invoicing.tax_rate', 6),
            'currency' => Setting::get('invoicing.currency', 'RM'),
            'work_start_time' => Setting::get('attendance.work_start_time', '09:00'),
            'late_grace_minutes' => (int) Setting::get('attendance.late_grace_minutes', 15),
            'half_day_minutes' => (int) Setting::get('attendance.half_day_minutes', 240),
            'leave_annual_days' => (int) Setting::get('leave.annual_days', 14),
            'leave_sick_days' => (int) Setting::get('leave.sick_days', 14),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:190'],
            'stuck_threshold_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'dormant_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:8'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'work_start_time' => ['nullable', 'string', 'max:5'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
            'half_day_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'leave_annual_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'leave_sick_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $map = [
            'company_name' => 'app.company_name', 'tagline' => 'app.tagline',
            'stuck_threshold_days' => 'content.stuck_threshold_days', 'dormant_days' => 'leads.dormant_days',
            'tax_rate' => 'invoicing.tax_rate', 'currency' => 'invoicing.currency',
            'work_start_time' => 'attendance.work_start_time', 'late_grace_minutes' => 'attendance.late_grace_minutes',
            'half_day_minutes' => 'attendance.half_day_minutes',
            'leave_annual_days' => 'leave.annual_days', 'leave_sick_days' => 'leave.sick_days',
        ];
        foreach ($map as $field => $key) {
            if (array_key_exists($field, $data)) {
                Setting::put($key, $data[$field]);
            }
        }
        if ($request->hasFile('logo')) {
            Setting::put('app.logo', $request->file('logo')->store('branding', 'public'));
        }

        return $this->show();
    }
}
