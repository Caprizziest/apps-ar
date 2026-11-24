<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerDpTransaction;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceInstallment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;

class ArTransformer
{
    public static function user(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'username' => $user->username,
            'fullName' => $user->full_name,
            'role' => $user->role,
        ];
    }

    public static function bankAccount(BankAccount $bank): array
    {
        return [
            'id' => (string) $bank->id,
            'bankName' => $bank->bank_name,
            'accountNumber' => $bank->account_number,
            'accountHolder' => $bank->account_holder,
            'isDefault' => (bool) $bank->is_default,
        ];
    }

    public static function customer(Customer $customer): array
    {
        return [
            'id' => (string) $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'contactPerson' => $customer->contact_person ?? '',
            'email' => $customer->email ?? '',
            'phone' => $customer->phone ?? '',
            'address' => $customer->address ?? '',
            'nik' => $customer->nik ?: undefined_if_null($customer->nik),
            'npwp' => $customer->npwp ?: undefined_if_null($customer->npwp),
            'creditLimit' => (float) $customer->credit_limit,
            'dueDays' => (int) $customer->due_days,
            'status' => $customer->status,
            'notes' => $customer->notes ?? '',
            'createdAt' => $customer->created_at ? $customer->created_at->format('Y-m-d') : '2026-01-01',
            'dpBalance' => (float) $customer->dp_balance,
        ];
    }

    public static function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['customer', 'details', 'installments', 'penalty', 'createdBy']);

        $penalty = $invoice->penalty;

        $data = [
            'id' => (string) $invoice->id,
            'invoiceNumber' => $invoice->invoice_number,
            'journalNumber' => $invoice->journal_number,
            'customerId' => (string) $invoice->customer_id,
            'customerName' => $invoice->customer?->name ?? 'Unknown Customer',
            'customerNik' => $invoice->customer_nik ?? $invoice->customer?->nik,
            'customerNpwp' => $invoice->customer_npwp ?? $invoice->customer?->npwp,
            'issueDate' => $invoice->issue_date ? $invoice->issue_date->format('Y-m-d') : '',
            'dueDate' => $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '',
            'status' => $invoice->status,
            'invoiceType' => $invoice->invoice_type,
            'lineItems' => $invoice->details->map(fn (InvoiceDetail $d) => [
                'id' => (string) $d->id,
                'description' => $d->description,
                'itemType' => $d->item_type,
                'quantity' => (int) $d->quantity,
                'unitPrice' => (float) $d->unit_price,
                'lineTotal' => (float) $d->line_total,
            ])->values()->all(),
            'subtotal' => (float) $invoice->subtotal,
            'taxRate' => (float) $invoice->tax_rate,
            'taxAmount' => (float) $invoice->tax_amount,
            'pphRate' => (float) $invoice->pph_rate,
            'pphAmount' => (float) $invoice->pph_amount,
            'dpDeduction' => (float) $invoice->dp_deduction,
            'total' => (float) $invoice->total,
            'amountPaid' => (float) $invoice->amount_paid,
            'balanceDue' => (float) $invoice->balance_due,
            'notes' => $invoice->notes ?? '',
            'sourceType' => $invoice->source_type ?? 'generic',
            'createdById' => $invoice->created_by_id ? (string) $invoice->created_by_id : null,
            'createdByName' => $invoice->createdBy?->full_name ?? 'Staf Penagihan AR',
            'createdAt' => $invoice->issue_date ? $invoice->issue_date->format('Y-m-d') : ($invoice->created_at?->format('Y-m-d') ?? '2026-09-16'),
            'isInstallment' => (bool) $invoice->is_installment,
            'installmentCount' => $invoice->installment_count,
            'installmentIntervalDays' => $invoice->installment_interval_days,
            'installmentSchedule' => $invoice->installments->map(fn (InvoiceInstallment $inst) => [
                'installmentNumber' => (int) $inst->installment_number,
                'dueDate' => $inst->due_date ? $inst->due_date->format('Y-m-d') : '',
                'amount' => (float) $inst->amount,
                'amountPaid' => (float) $inst->amount_paid,
                'status' => $inst->status,
                'paidDate' => $inst->paid_date ? $inst->paid_date->format('Y-m-d') : null,
            ])->values()->all(),
            'timesOverdue' => (int) $invoice->times_overdue,
            'hasRollover' => (bool) $invoice->has_rollover,
            'rolledOverAmount' => (float) $invoice->rolled_over_amount,
            'rolledOverFrom' => $invoice->rolled_over_from,
            'penalty' => $penalty ? [
                'penaltyAmount' => (float) $penalty->penalty_amount,
                'paidDate' => $penalty->paid_date ? $penalty->paid_date->format('Y-m-d') : null,
                'paymentJournalNumber' => $penalty->payment_journal_number,
                'paymentNotes' => $penalty->payment_notes,
                'paidAmount' => (float) $penalty->paid_amount,
                'waivedDate' => $penalty->waived_date ? $penalty->waived_date->format('Y-m-d') : null,
                'waiverMemoNumber' => $penalty->waiver_memo_number,
                'waiverNotes' => $penalty->waiver_notes,
                'waivedAmount' => (float) $penalty->waived_amount,
                'balanceDue' => (float) $penalty->balance_due,
            ] : null,
        ];

        if ($invoice->status === 'Cancelled') {
            $data['cancellationReason'] = $invoice->cancellation_reason;
            $data['cancelledAt'] = $invoice->cancelled_at ? $invoice->cancelled_at->format('Y-m-d') : null;
            $data['cancelledBy'] = $invoice->cancelled_by_name ?? 'Manager Keuangan';
        }

        return $data;
    }

    public static function payment(Payment $payment): array
    {
        $payment->loadMissing(['customer', 'allocations.invoice']);

        return [
            'id' => (string) $payment->id,
            'paymentNumber' => $payment->payment_number,
            'journalNumber' => $payment->journal_number,
            'kwitansiNumber' => $payment->kwitansi_number,
            'customerId' => (string) $payment->customer_id,
            'customerName' => $payment->customer?->name ?? 'Unknown Customer',
            'paymentDate' => $payment->payment_date ? $payment->payment_date->format('Y-m-d') : '',
            'amount' => (float) $payment->amount,
            'discountAmount' => (float) $payment->discount_amount,
            'method' => $payment->method,
            'paymentChannel' => $payment->payment_channel ?? 'Bank Transfer',
            'referenceNumber' => $payment->reference_number ?? '',
            'adminFee' => (float) $payment->admin_fee,
            'bankAccountId' => $payment->bank_account_id ? (string) $payment->bank_account_id : null,
            'notes' => $payment->notes ?? '',
            'allocations' => $payment->allocations->map(fn (PaymentAllocation $a) => [
                'invoiceId' => (string) $a->invoice_id,
                'invoiceNumber' => $a->invoice?->invoice_number ?? '-',
                'allocatedAmount' => (float) $a->allocated_amount,
                'timesPaid' => (int) $a->times_paid,
            ])->values()->all(),
            'isDownPayment' => (bool) $payment->is_down_payment,
            'createdAt' => $payment->payment_date ? $payment->payment_date->format('Y-m-d') : ($payment->created_at?->format('Y-m-d') ?? '2026-09-16'),
            'auditStatus' => $payment->audit_status ?? 'pending_fa',
            'auditedBy' => $payment->audited_by_name,
            'auditedAt' => $payment->audited_at ? $payment->audited_at->format('Y-m-d H:i') : null,
            'auditNotes' => $payment->audit_notes,
            'attachmentName' => $payment->attachment_name,
            'attachmentSize' => $payment->attachment_size,
            'attachmentUrl' => $payment->attachment_url,
            'overpaymentToDeposit' => (float) $payment->overpayment_to_deposit,
        ];
    }

    public static function dpTransaction(CustomerDpTransaction $tx): array
    {
        $tx->loadMissing(['customer', 'invoice', 'payment']);

        return [
            'id' => (string) $tx->id,
            'customerId' => (string) $tx->customer_id,
            'customerName' => $tx->customer?->name ?? 'Unknown Customer',
            'date' => $tx->transaction_date ? $tx->transaction_date->format('Y-m-d') : '',
            'type' => $tx->type,
            'amount' => (float) $tx->amount,
            'balanceAfter' => (float) $tx->balance_after,
            'invoiceNumber' => $tx->invoice?->invoice_number ?? ($tx->type === 'applied' ? $tx->reference_number : null),
            'paymentNumber' => $tx->payment?->payment_number ?? null,
            'referenceNumber' => $tx->reference_number,
            'notes' => $tx->notes ?? '',
        ];
    }

    public static function activityLog(ActivityLog $log): array
    {
        return [
            'id' => (string) $log->id,
            'timestamp' => $log->logged_at ? $log->logged_at->format('Y-m-d H:i') : ($log->created_at?->format('Y-m-d H:i') ?? '2026-09-16 09:00'),
            'user' => $log->user_name ?? 'Staf Penagihan AR',
            'role' => $log->user_role ?? 'User',
            'type' => $log->type,
            'description' => $log->description,
            'amount' => $log->amount !== null ? (float) $log->amount : null,
            'referenceId' => $log->reference_id,
            'badgeType' => $log->badge_type ?? 'info',
        ];
    }
}

function undefined_if_null($val)
{
    return $val ?: null;
}
