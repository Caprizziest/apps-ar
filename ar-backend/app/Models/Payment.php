<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_number',
        'journal_number',
        'kwitansi_number',
        'customer_id',
        'bank_account_id',
        'payment_date',
        'amount',
        'discount_amount',
        'admin_fee',
        'method',
        'payment_channel',
        'reference_number',
        'is_down_payment',
        'overpayment_to_deposit',
        'notes',
        'audit_status',
        'audited_by_id',
        'audited_by_name',
        'audited_at',
        'audit_notes',
        'attachment_name',
        'attachment_size',
        'attachment_url',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount' => 'float',
            'discount_amount' => 'float',
            'admin_fee' => 'float',
            'is_down_payment' => 'boolean',
            'overpayment_to_deposit' => 'float',
            'audited_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function auditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
