<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->is_overdue ? 'overdue' : $this->status;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact?->only('id', 'business_name', 'email', 'phone', 'address')),
            'lead_id' => $this->lead_id,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $status,
            'raw_status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => $this->balance,
            'notes' => $this->notes,
            'terms' => $this->terms,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id, 'description' => $i->description,
                'quantity' => (float) $i->quantity, 'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method,
                'reference' => $p->reference, 'paid_on' => $p->paid_on?->toDateString(),
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
