<?php

namespace App\Models;

use App\Support\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'code', 'contact_id', 'lead_id', 'created_by', 'issue_date', 'due_date', 'status',
        'subtotal', 'tax_rate', 'tax_amount', 'discount_amount', 'total', 'amount_paid',
        'notes', 'terms', 'sent_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime',
            'subtotal' => 'decimal:2', 'tax_rate' => 'decimal:2', 'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'total' => 'decimal:2', 'amount_paid' => 'decimal:2',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('paid_on');
    }

    /** Recompute money fields from the line items + tax/discount. */
    public function recalcTotals(): void
    {
        $subtotal = (float) $this->items()->sum('line_total');
        $discount = (float) $this->discount_amount;
        $taxable = max(0, $subtotal - $discount);
        $tax = round($taxable * ((float) $this->tax_rate) / 100, 2);

        $this->subtotal = $subtotal;
        $this->tax_amount = $tax;
        $this->total = $taxable + $tax;
    }

    /** Recompute amount paid + status from payments. */
    public function recalcPaymentStatus(): void
    {
        if ($this->status === 'void') {
            return;
        }
        $paid = (float) $this->payments()->sum('amount');
        $this->amount_paid = $paid;

        if ($this->total > 0 && $paid >= $this->total) {
            $this->status = 'paid';
            $this->paid_at = $this->paid_at ?? now();
        } elseif ($paid > 0) {
            $this->status = 'partial';
            $this->paid_at = null;
        } else {
            $this->status = $this->sent_at ? 'sent' : 'draft';
            $this->paid_at = null;
        }
    }

    /** Derived: overdue if past due and not settled. */
    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, ['sent', 'partial'], true)
            && $this->due_date && $this->due_date->isPast();
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->total - (float) $this->amount_paid;
    }

    public static function nextCode(): string
    {
        $max = (int) (static::withTrashed()->max('id') ?? 0);

        return 'INV-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
