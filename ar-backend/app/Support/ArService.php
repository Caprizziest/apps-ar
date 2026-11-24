<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;

class ArService
{
    /**
     * System reference date matching frontend prototype (`2026-09-16`),
     * or can be overridden via request parameter.
     */
    public const TODAY = '2026-09-16';

    public static function deriveInvoiceStatus(string $currentStatus, float $amountPaid, float $total, string $dueDate, ?string $referenceDate = null): string
    {
        if ($currentStatus === 'Cancelled') {
            return 'Cancelled';
        }
        if ($currentStatus === 'Draft') {
            return 'Draft';
        }
        if ($amountPaid >= $total && $total > 0) {
            return 'Paid';
        }

        $today = $referenceDate ?? self::TODAY;
        if ($dueDate < $today) {
            return 'Overdue';
        }
        if ($amountPaid > 0) {
            return 'Partially Paid';
        }

        return 'Issued';
    }

    public static function nextCustomerCode(): string
    {
        $count = Customer::count() + 1;
        do {
            $code = 'CUST-' . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            $count++;
        } while (Customer::where('code', $code)->exists());

        return $code;
    }

    public static function nextInvoiceNumber(): string
    {
        $count = Invoice::withTrashed()->count() + 1;
        do {
            $number = 'INV-2026-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $count++;
        } while (Invoice::withTrashed()->where('invoice_number', $number)->exists());

        return $number;
    }

    public static function nextPaymentNumber(): string
    {
        $count = Payment::withTrashed()->count() + 1;
        do {
            $number = 'PAY-2026-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
            $count++;
        } while (Payment::withTrashed()->where('payment_number', $number)->exists());

        return $number;
    }

    public static function resolveActiveUser(?Request $request = null): User
    {
        $role = $request?->header('X-User-Role') ?? $request?->input('activeRole');
        if ($role) {
            $user = User::where('role', $role)->first();
            if ($user) {
                return $user;
            }
        }

        return User::where('role', 'Admin')->first()
            ?? User::first()
            ?? new User(['id' => 1, 'username' => 'finance.ar', 'full_name' => 'Staf Penagihan AR', 'role' => 'Admin']);
    }

    public static function logActivity(array $payload, ?Request $request = null): ActivityLog
    {
        $user = self::resolveActiveUser($request);

        return ActivityLog::create([
            'user_id' => $user->id ?? null,
            'user_name' => $payload['user_name'] ?? $user->full_name ?? 'Staf Penagihan AR',
            'user_role' => $payload['user_role'] ?? $user->role ?? 'Admin',
            'type' => $payload['type'],
            'description' => $payload['description'],
            'amount' => $payload['amount'] ?? null,
            'reference_id' => $payload['reference_id'] ?? null,
            'badge_type' => $payload['badge_type'] ?? 'info',
            'logged_at' => now(),
        ]);
    }
}
