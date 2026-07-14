<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1e293b; font-size: 12px; margin: 0; }
        .wrap { padding: 36px 40px; }
        .brand { color: #1d4ed8; font-size: 24px; font-weight: bold; }
        .muted { color: #64748b; }
        h1 { font-size: 18px; margin: 4px 0 2px; }
        .cards { width: 100%; margin: 20px 0; border-collapse: separate; border-spacing: 8px 0; }
        .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; }
        .card .k { font-size: 10px; text-transform: uppercase; color: #64748b; letter-spacing: .04em; }
        .card .v { font-size: 18px; font-weight: bold; margin-top: 2px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .items th { background: #f1f5f9; text-align: left; padding: 8px 10px; font-size: 10px; text-transform: uppercase; color: #475569; }
        .items td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
        .right { text-align: right; }
        .foot { margin-top: 32px; color: #94a3b8; font-size: 10px; }
        .bar { height: 6px; background: #e2e8f0; border-radius: 4px; width: 90px; display: inline-block; overflow: hidden; vertical-align: middle; }
        .bar > span { display: block; height: 6px; background: #1d4ed8; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">Malayznbeat</div>
    <div class="muted">Content &amp; Growth Agency</div>
    <h1>Work activity report{{ !empty($report['employee']) ? ' · '.$report['employee'] : '' }}</h1>
    <div class="muted">{{ $report['range']['label'] }} &middot; {{ $report['range']['from'] }} &rarr; {{ $report['range']['to'] }}</div>

    <table class="cards">
        <tr>
            <td class="card" width="20%"><div class="k">Employees</div><div class="v">{{ $report['summary']['employees'] }}</div></td>
            <td class="card" width="20%"><div class="k">Updates</div><div class="v">{{ $report['summary']['submitted'] }}/{{ $report['summary']['expected'] }}</div></td>
            <td class="card" width="20%"><div class="k">Compliance</div><div class="v">{{ $report['summary']['compliance'] }}%</div></td>
            <td class="card" width="20%"><div class="k">Missed</div><div class="v">{{ $report['summary']['missed'] }}</div></td>
            <td class="card" width="20%"><div class="k">Hours logged</div><div class="v">{{ $report['summary']['hours_logged'] }}</div></td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Employee</th>
                <th class="right">Expected</th>
                <th class="right">Submitted</th>
                <th class="right">Missed</th>
                <th class="right">Hours</th>
                <th>Compliance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['employees'] as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    <td class="right">{{ $e['expected'] }}</td>
                    <td class="right">{{ $e['submitted'] }}</td>
                    <td class="right">{{ $e['missed'] }}</td>
                    <td class="right">{{ $e['hours_logged'] }}</td>
                    <td><span class="bar"><span style="width: {{ $e['compliance'] }}%"></span></span> {{ $e['compliance'] }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No activity in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (!empty($report['days']))
        <h1 style="margin-top:26px;">Daily breakdown</h1>
        <table class="items">
            <thead><tr><th>Date</th><th class="right">Expected</th><th class="right">Submitted</th><th class="right">Missed</th><th>Compliance</th></tr></thead>
            <tbody>
                @foreach ($report['days'] as $d)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($d['date'])->format('D, M j') }}</td>
                        <td class="right">{{ $d['expected'] }}</td>
                        <td class="right">{{ $d['submitted'] }}</td>
                        <td class="right">{{ $d['missed'] }}</td>
                        <td><span class="bar"><span style="width: {{ $d['compliance'] }}%"></span></span> {{ $d['compliance'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (!empty($report['entries']))
        <h1 style="margin-top:26px;">Hourly entries</h1>
        <table class="items">
            <thead><tr><th>Date</th><th>Hour</th><th>Mode</th><th>Note</th><th>Linked to</th></tr></thead>
            <tbody>
                @foreach ($report['entries'] as $e)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($e['date'])->format('M j') }}</td>
                        <td>{{ $e['hour'] }}{!! $e['is_late'] ? ' <span class="muted">(late)</span>' : '' !!}</td>
                        <td>{{ $e['mode_label'] }}</td>
                        <td>{{ $e['note'] ?: '—' }}</td>
                        <td>{{ $e['link_label'] ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="foot">Generated {{ now()->format('M j, Y g:i A') }} &middot; Malayznbeat CRM</div>
</div>
</body>
</html>
