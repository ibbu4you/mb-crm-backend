<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->input('q'));
        if (strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = "%{$q}%";
        $user = $request->user();
        $groups = [];

        if ($user->canAny(['leads.view', 'leads.view.all', 'contacts.view', 'contacts.view.all', 'sales.reports.view'])) {
            $items = Contact::where('business_name', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phone', 'like', $like)->limit(6)->get();
            $groups[] = $this->group('Contacts & Clients', $items->map(fn ($c) => ['label' => $c->business_name, 'sub' => $c->contact_person ?? $c->phone, 'to' => '/leads']));
        }
        if ($user->can('content.articles.view')) {
            $items = Article::where('title', 'like', $like)->orWhere('article_code', 'like', $like)->limit(6)->get();
            $groups[] = $this->group('Articles', $items->map(fn ($a) => ['label' => $a->title, 'sub' => $a->article_code, 'to' => '/articles']));
        }
        if ($user->canAny(['support.view', 'support.handle'])) {
            $items = SupportTicket::where('subject', 'like', $like)->orWhere('code', 'like', $like)
                ->when(! $user->can('support.handle'), fn ($x) => $x->where('reporter_id', $user->id))->limit(6)->get();
            $groups[] = $this->group('Support', $items->map(fn ($t) => ['label' => $t->subject, 'sub' => $t->code, 'to' => '/support']));
        }
        if ($user->canAny(['invoicing.view', 'invoicing.manage'])) {
            $items = Invoice::where('code', 'like', $like)->limit(6)->get();
            $groups[] = $this->group('Invoices', $items->map(fn ($i) => ['label' => $i->code, 'sub' => 'RM '.number_format($i->total, 2), 'to' => '/invoices']));
        }
        if ($user->can('tasks.view')) {
            $items = Task::where('title', 'like', $like)->where(fn ($x) => $x->where('assignee_id', $user->id)->orWhere('created_by', $user->id))->limit(6)->get();
            $groups[] = $this->group('Tasks', $items->map(fn ($t) => ['label' => $t->title, 'sub' => $t->status, 'to' => '/tasks']));
        }
        if ($user->canAny(['employees.view', 'employees.manage'])) {
            $items = User::where('name', 'like', $like)->orWhere('email', 'like', $like)->limit(6)->get();
            $groups[] = $this->group('Employees', $items->map(fn ($u) => ['label' => $u->name, 'sub' => $u->email, 'to' => '/employees']));
        }

        return response()->json(['groups' => array_values(array_filter($groups, fn ($g) => count($g['items']) > 0))]);
    }

    private function group(string $label, $items): array
    {
        return ['label' => $label, 'items' => $items->values()];
    }
}
