<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $q = Invoice::query()->with('contact');
        if ($status = $request->input('status')) {
            if ($status === 'overdue') {
                $q->whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', today());
            } else {
                $q->where('status', $status);
            }
        }
        if ($search = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhereHas('contact', fn ($c) => $c->where('business_name', 'like', "%{$search}%")));
        }

        return InvoiceResource::collection($q->latest()->paginate($request->integer('per_page', 25)));
    }

    public function stats()
    {
        $invoiced = (float) Invoice::where('status', '!=', 'void')->sum('total');
        $paid = (float) Invoice::sum('amount_paid');
        $overdue = Invoice::whereIn('status', ['sent', 'partial'])->whereDate('due_date', '<', today());

        return response()->json([
            'invoiced' => $invoiced,
            'paid' => $paid,
            'outstanding' => round($invoiced - $paid, 2),
            'overdue_count' => (clone $overdue)->count(),
            'overdue_amount' => round((float) (clone $overdue)->sum(DB::raw('total - amount_paid')), 2),
            'by_status' => Invoice::select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status'),
        ]);
    }

    public function show(Invoice $invoice)
    {
        return new InvoiceResource($invoice->load(['contact', 'items', 'payments']));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $invoice = Invoice::create([
            'code' => Invoice::nextCode(),
            'contact_id' => $data['contact_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'created_by' => $request->user()->id,
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => 'draft',
            'tax_rate' => $data['tax_rate'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
        ]);
        $this->syncItems($invoice, $data['items']);
        $invoice->recalcTotals();
        $invoice->save();

        return (new InvoiceResource($invoice->load(['contact', 'items', 'payments'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Invoice $invoice)
    {
        abort_if(in_array($invoice->status, ['paid', 'void'], true), 422, 'A paid or void invoice cannot be edited.');
        $data = $this->validateData($request);
        $invoice->update([
            'contact_id' => $data['contact_id'] ?? null,
            'lead_id' => $data['lead_id'] ?? null,
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
        ]);
        $invoice->items()->delete();
        $this->syncItems($invoice, $data['items']);
        $invoice->recalcTotals();
        $invoice->recalcPaymentStatus();
        $invoice->save();

        return new InvoiceResource($invoice->load(['contact', 'items', 'payments']));
    }

    public function send(Invoice $invoice)
    {
        if (! $invoice->sent_at) {
            $invoice->sent_at = now();
        }
        $invoice->recalcPaymentStatus();
        $invoice->save();

        return new InvoiceResource($invoice->load(['contact', 'items', 'payments']));
    }

    public function void(Invoice $invoice)
    {
        $invoice->update(['status' => 'void']);

        return new InvoiceResource($invoice->load(['contact', 'items', 'payments']));
    }

    public function destroy(Invoice $invoice)
    {
        $invoice->delete();

        return response()->json(['message' => 'Invoice deleted.']);
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['contact', 'items', 'payments']);
        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice]);

        return $pdf->stream("{$invoice->code}.pdf");
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'contact_id' => ['nullable', 'exists:contacts,id'],
            'lead_id' => ['nullable', 'exists:leads,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }

    private function syncItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $i => $item) {
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'sort_order' => $i,
            ]);
        }
    }
}
