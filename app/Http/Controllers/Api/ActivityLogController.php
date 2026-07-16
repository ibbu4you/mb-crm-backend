<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    /** Class-basename => friendly module label, used across the log + filters. */
    private const MODULES = [
        'Lead' => 'Lead',
        'Contact' => 'Contact',
        'Invoice' => 'Invoice',
        'Payment' => 'Payment',
        'Task' => 'Task',
        'Article' => 'Article',
        'Visit' => 'Visit',
        'SupportTicket' => 'Support ticket',
        'ViralPackage' => 'Viral package',
        'Deal' => 'Deal',
        'FollowUp' => 'Follow-up',
        'Target' => 'Target',
        'PortfolioItem' => 'Portfolio item',
        'CompanyDocument' => 'Document',
        'User' => 'Employee',
    ];

    /** Attributes tried (in order) to build a human label for a subject. */
    private const LABEL_KEYS = ['business_name', 'name', 'title', 'code', 'invoice_no', 'number', 'reference'];

    public function index(Request $request)
    {
        $q = Activity::query()->with(['causer', 'subject'])->latest();

        if ($event = $request->input('event')) {
            $q->where('event', $event);
        }
        if ($type = $request->input('subject_type')) {
            $q->where('subject_type', $type);
        }
        if ($causer = $request->input('causer_id')) {
            $q->where('causer_id', $causer);
        }
        if ($from = $request->input('date_from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $q->whereDate('created_at', '<=', $to);
        }
        if ($term = trim((string) $request->input('q'))) {
            $q->where(function ($w) use ($term) {
                $w->where('description', 'like', "%{$term}%")
                    ->orWhere('subject_type', 'like', "%{$term}%")
                    ->orWhereHas('causer', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        $page = $q->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (Activity $a) => $this->row($a))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Filter facets + headline stats for the viewer. */
    public function catalog()
    {
        $now = now();

        $types = Activity::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type');
        $users = Activity::query()->whereNotNull('causer_id')->with('causer')->get()
            ->pluck('causer')->filter()->unique('id')->sortBy('name')->values()
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return response()->json([
            'stats' => [
                'total' => Activity::count(),
                'today' => Activity::whereDate('created_at', $now->toDateString())->count(),
                'week' => Activity::where('created_at', '>=', $now->copy()->startOfWeek())->count(),
                'actors' => (int) Activity::whereNotNull('causer_id')->distinct()->count('causer_id'),
            ],
            'events' => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all(),
            'modules' => $types->map(fn ($t) => [
                'value' => $t,
                'label' => self::MODULES[class_basename($t)] ?? class_basename($t),
            ])->sortBy('label')->values()->all(),
            'users' => $users->all(),
        ]);
    }

    private function row(Activity $a): array
    {
        $attributes = data_get($a->properties, 'attributes');
        $old = data_get($a->properties, 'old');

        $changes = [];
        if (is_array($attributes)) {
            foreach ($attributes as $key => $value) {
                if (in_array($key, ['updated_at', 'created_at'], true)) {
                    continue;
                }
                $changes[] = [
                    'field' => $key,
                    'old' => is_array($old) ? ($old[$key] ?? null) : null,
                    'new' => $value,
                ];
            }
        }

        return [
            'id' => $a->id,
            'event' => $a->event,
            'description' => $a->description,
            'module' => $a->subject_type
                ? (self::MODULES[class_basename($a->subject_type)] ?? class_basename($a->subject_type))
                : null,
            'subject_id' => $a->subject_id,
            'subject_label' => $this->label($a),
            'causer' => $a->causer
                ? ['id' => $a->causer->id, 'name' => $a->causer->name, 'avatar_url' => $a->causer->avatar_url]
                : null,
            'changes' => $changes,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    private function label(Activity $a): ?string
    {
        $subject = $a->subject;
        if (! $subject) {
            return null;
        }
        foreach (self::LABEL_KEYS as $key) {
            $value = $subject->getAttribute($key);
            if (! empty($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
