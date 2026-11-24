<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'nik',
        'npwp',
        'credit_limit',
        'due_days',
        'status',
        'dp_balance',
        'company_unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'float',
            'due_days' => 'integer',
            'dp_balance' => 'float',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function dpTransactions(): HasMany
    {
        return $this->hasMany(CustomerDpTransaction::class);
    }
}
