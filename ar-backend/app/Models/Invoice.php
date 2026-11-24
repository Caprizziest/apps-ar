<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'journal_number',
        'customer_id',
        'customer_nik',
        'customer_npwp',
        'issue_date',
        'due_date',
        'status',
        'invoice_type',
        'source_type',
        'company_unit',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'pph_rate',
        'pph_amount',
        'dp_deduction',
        'total',
        'amount_paid',
        'balance_due',
        'notes',
        'is_installment',
        'installment_count',
        'installment_interval_days',
        'times_overdue',
        'has_rollover',
        'rolled_over_amount',
        'rolled_over_from',
        'rolled_over_from_invoice_id',
        'created_by_id',
        'cancelled_by_id',
        'cancelled_by_name',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'pph_rate' => 'float',
            'pph_amount' => 'float',
            'dp_deduction' => 'float',
            'total' => 'float',
            'amount_paid' => 'float',
            'balance_due' => 'float',
            'is_installment' => 'boolean',
            'has_rollover' => 'boolean',
            'rolled_over_amount' => 'float',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(InvoiceInstallment::class);
    }

    public function penalty(): HasOne
    {
        return $this->hasOne(InvoicePenalty::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }
}
