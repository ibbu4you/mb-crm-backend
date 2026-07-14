<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class InvoicingSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Go Viral Package', 'sku' => 'PKG-VIRAL', 'price' => 1500, 'sort_order' => 1],
            ['name' => 'Bronze Package', 'sku' => 'PKG-BRONZE', 'price' => 2000, 'sort_order' => 2],
            ['name' => 'Silver Package', 'sku' => 'PKG-SILVER', 'price' => 5000, 'sort_order' => 3],
            ['name' => 'Platinum Package', 'sku' => 'PKG-PLATINUM', 'price' => 10000, 'sort_order' => 4],
            ['name' => 'SEO Article', 'sku' => 'SVC-SEO', 'price' => 300, 'sort_order' => 5],
            ['name' => 'Social Media Management (monthly)', 'sku' => 'SVC-SMM', 'price' => 800, 'sort_order' => 6],
        ];
        foreach ($products as $p) {
            Product::updateOrCreate(['sku' => $p['sku']], $p);
        }
        $catalog = Product::pluck('price', 'name');
        $admin = User::where('email', 'admin@malayznbeat.com')->value('id');
        $contacts = Contact::limit(6)->get();

        // [ [items...], tax_rate, discount, days_due, pay ]  pay: null|'full'|'partial'
        $specs = [
            [[['Platinum Package', 1, 10000]], 6, 0, 14, 'full'],
            [[['Silver Package', 1, 5000], ['SEO Article', 3, 300]], 6, 200, 14, 'partial'],
            [[['Go Viral Package', 1, 1500]], 0, 0, 7, null],       // draft
            [[['Bronze Package', 1, 2000]], 6, 0, -5, null],        // overdue (past due, sent)
            [[['Social Media Management (monthly)', 2, 800]], 0, 0, 10, 'full'],
        ];

        foreach ($specs as $i => [$items, $tax, $discount, $daysDue, $pay]) {
            $contact = $contacts[$i % count($contacts)];
            if (Invoice::where('contact_id', $contact->id)->whereDate('issue_date', today()->subDays($i))->exists()) {
                continue;
            }
            $invoice = Invoice::create([
                'code' => Invoice::nextCode(),
                'contact_id' => $contact->id,
                'created_by' => $admin,
                'issue_date' => today()->subDays($i + 2),
                'due_date' => today()->addDays($daysDue),
                'status' => 'draft',
                'tax_rate' => $tax,
                'discount_amount' => $discount,
                'terms' => 'Payment due within the stated period. Bank: Maybank 5123-xxxx.',
            ]);
            foreach ($items as $k => [$name, $qty, $price]) {
                $invoice->items()->create(['description' => $name, 'quantity' => $qty, 'unit_price' => $price, 'sort_order' => $k]);
            }
            $invoice->recalcTotals();

            // draft #3 stays draft; others are sent
            if ($i !== 2) {
                $invoice->sent_at = now()->subDays($i);
            }
            $invoice->recalcPaymentStatus();
            $invoice->save();

            if ($pay === 'full') {
                $invoice->payments()->create(['recorded_by' => $admin, 'amount' => $invoice->total, 'method' => 'bank_transfer', 'reference' => 'TT'.rand(10000, 99999), 'paid_on' => today()->subDays($i)]);
            } elseif ($pay === 'partial') {
                $invoice->payments()->create(['recorded_by' => $admin, 'amount' => round($invoice->total / 2, 2), 'method' => 'bank_transfer', 'reference' => 'TT'.rand(10000, 99999), 'paid_on' => today()->subDays($i)]);
            }
            if ($pay) {
                $invoice->recalcPaymentStatus();
                $invoice->save();
            }
        }
    }
}
