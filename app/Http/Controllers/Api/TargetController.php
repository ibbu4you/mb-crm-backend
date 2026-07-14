<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Target;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;

class TargetController extends Controller
{
    /** Grid of every salesperson's target vs actuals for a period (YYYY-MM). */
    public function index(Request $request)
    {
        $period = $request->input('period', Target::currentPeriod());
        [$year, $month] = array_map('intval', explode('-', $period));

        $users = User::whereHas('roles', fn ($r) => $r->whereIn('name', ['Salesperson', 'Manager']))
            ->orWhereHas('permissions', fn ($p) => $p->where('name', 'sales.visits.log'))
            ->get();
        // Fallback: if none matched, show all active users.
        if ($users->isEmpty()) {
            $users = User::where('is_active', true)->get();
        }

        $targets = Target::where('period', $period)->get()->keyBy('user_id');

        $rows = $users->map(function (User $u) use ($targets, $year, $month) {
            $t = $targets->get($u->id);
            $visitsActual = Visit::where('user_id', $u->id)->whereYear('visit_date', $year)->whereMonth('visit_date', $month)->count();
            $revenueActual = Deal::where('user_id', $u->id)->where('outcome', 'won')->whereYear('closed_at', $year)->whereMonth('closed_at', $month)->sum('actual_revenue');

            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'visits_target' => $t?->visits_target,
                'revenue_target' => $t ? (float) $t->revenue_target : null,
                'visits_actual' => $visitsActual,
                'revenue_actual' => (float) $revenueActual,
            ];
        });

        return response()->json(['period' => $period, 'data' => $rows->values()]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'period' => ['required', 'string', 'size:7'],
            'visits_target' => ['nullable', 'integer', 'min:0'],
            'revenue_target' => ['nullable', 'numeric', 'min:0'],
        ]);

        $target = Target::updateOrCreate(
            ['user_id' => $data['user_id'], 'period' => $data['period']],
            ['visits_target' => $data['visits_target'] ?? null, 'revenue_target' => $data['revenue_target'] ?? null],
        );

        return response()->json(['data' => $target]);
    }
}
