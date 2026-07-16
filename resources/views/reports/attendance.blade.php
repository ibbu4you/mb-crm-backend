<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 11px; margin: 0; }
        .wrap { padding: 26px 30px; }
        .brand { color: #1d4ed8; font-size: 22px; font-weight: bold; }
        .muted { color: #64748b; }
        h1 { font-size: 16px; margin: 4px 0 2px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .items th { background: #f1f5f9; text-align: left; padding: 6px 8px; font-size: 10px; text-transform: uppercase; color: #475569; }
        .items td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; }
        .right { text-align: right; }
        .center { text-align: center; }
        .foot { margin-top: 24px; color: #94a3b8; font-size: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">Malayznbeat</div>
    <div class="muted">Content &amp; Growth Agency</div>
    <h1>Attendance report</h1>
    <div class="muted">{{ $reg['label'] }} &middot; {{ $reg['start'] }} &rarr; {{ $reg['end'] }}</div>

    <table class="items">
        <thead>
            <tr>
                <th>Employee</th>
                <th class="center">Present</th>
                <th class="center">On-site</th>
                <th class="center">Field</th>
                <th class="center">Late</th>
                <th class="center">Half day</th>
                <th class="center">Hours</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($reg['employees'] as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    <td class="center">{{ $e['present'] }}</td>
                    <td class="center">{{ $e['present'] - $e['field'] }}</td>
                    <td class="center">{{ $e['field'] }}</td>
                    <td class="center">{{ $e['late'] }}</td>
                    <td class="center">{{ $e['half_day'] }}</td>
                    <td class="center">{{ $e['work_hours'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No employees.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="foot">Generated {{ now()->format('M j, Y g:i A') }} &middot; Malayznbeat CRM</div>
</div>
</body>
</html>
