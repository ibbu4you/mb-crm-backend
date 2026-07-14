<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice)
    {
        abort_if($invoice->status === 'void', 422, 'Cannot record a payment on a void invoice.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'ewallet', 'cheque'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $invoice->payments()->create([
            'recorded_by' => $request->user()->id,
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_on' => $data['paid_on'] ?? today(),
            'notes' => $data['notes'] ?? null,
        ]);

        $invoice->recalcPaymentStatus();
        $invoice->save();

        return new InvoiceResource($invoice->fresh()->load(['contact', 'items', 'payments']));
    }

    public function destroy(Payment $payment)
    {
        $invoice = $payment->invoice;
        $payment->delete();
        $invoice->recalcPaymentStatus();
        $invoice->save();

        return new InvoiceResource($invoice->fresh()->load(['contact', 'items', 'payments']));
    }
}
