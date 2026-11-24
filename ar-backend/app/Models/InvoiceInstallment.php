<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceInstallment extends Model
{
    protected $fillable = [
        'invoice_id',
        'installment_number',
        'due_date',
        'amount',
        'amount_paid',
        'status',
        'paid_date',
    ];

    protected function casts(): array
    {
        return [
            'installment_number' => 'integer',
            'due_date' => 'date:Y-m-d',
            'amount' => 'float',
            'amount_paid' => 'float',
            'paid_date' => 'date:Y-m-d',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
