<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePenalty extends Model
{
    protected $fillable = [
        'invoice_id',
        'penalty_amount',
        'paid_date',
        'payment_journal_number',
        'payment_notes',
        'paid_amount',
        'waived_date',
        'waiver_memo_number',
        'waiver_notes',
        'waived_amount',
        'waived_by_id',
        'balance_due',
    ];

    protected function casts(): array
    {
        return [
            'penalty_amount' => 'float',
            'paid_date' => 'date:Y-m-d',
            'paid_amount' => 'float',
            'waived_date' => 'date:Y-m-d',
            'waived_amount' => 'float',
            'balance_due' => 'float',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_id');
    }
}
