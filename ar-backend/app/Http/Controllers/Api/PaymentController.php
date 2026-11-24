<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDpTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function index(): JsonResponse
    {
        $payments = Payment::with(['customer', 'allocations.invoice'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $p) => ArTransformer::payment($p));

        return response()->json($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = Customer::find($request->input('customerId'));
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Pelanggan tidak ditemukan.'], 422);
        }

        $user = ArService::resolveActiveUser($request);

        return DB::transaction(function () use ($request, $customer, $user) {
            $paymentDate = $request->input('paymentDate', ArService::TODAY);
            $amount = (float) $request->input('amount', 0);
            $paymentNumber = ArService::nextPaymentNumber();
            $overpaymentToDeposit = (float) $request->input('overpaymentToDeposit', 0);

            $payment = Payment::create([
                'payment_number' => $paymentNumber,
                'journal_number' => $request->input('journalNumber')
                    ?: ('BD' . substr(str_replace('-', '', $paymentDate), 2) . random_int(100, 999)),
                'kwitansi_number' => $request->input('kwitansiNumber')
                    ?: (random_int(500, 999) . '/KLBR-FNC/MKS/IX/2026'),
                'customer_id' => $customer->id,
                'bank_account_id' => $request->input('bankAccountId') ?: null,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'discount_amount' => (float) $request->input('discountAmount', 0),
                'admin_fee' => (float) $request->input('adminFee', 0),
                'method' => $request->input('method', 'bank_transfer'),
                'payment_channel' => $request->input('paymentChannel', 'Bank Transfer'),
                'reference_number' => $request->input('referenceNumber', ''),
                'is_down_payment' => (bool) $request->input('isDownPayment', false),
                'overpayment_to_deposit' => $overpaymentToDeposit,
                'notes' => $request->input('notes', ''),
                'audit_status' => 'pending_fa',
                'attachment_name' => $request->input('attachmentName'),
                'attachment_size' => $request->input('attachmentSize'),
                'attachment_url' => $request->input('attachmentUrl'),
                'created_by_id' => $user->id,
            ]);

            $rawAllocations = $request->input('allocations', []);
            $updatedInvoices = [];

            if (is_array($rawAllocations)) {
                foreach ($rawAllocations as $alloc) {
                    $allocAmount = (float) ($alloc['allocatedAmount'] ?? 0);
                    if ($allocAmount <= 0) {
                        continue;
                    }

                    $inv = Invoice::find($alloc['invoiceId'] ?? null);
                    if (!$inv) {
                        continue;
                    }

                    $applied = min($allocAmount, (float) $inv->balance_due);
                    $newAmountPaid = (float) $inv->amount_paid + $applied;
                    $newBalanceDue = max(0, (float) $inv->total - $newAmountPaid);
                    $pastPaymentsCount = PaymentAllocation::where('invoice_id', $inv->id)->count();

                    PaymentAllocation::create([
                        'payment_id' => $payment->id,
                        'invoice_id' => $inv->id,
                        'allocated_amount' => $applied,
                        'times_paid' => $pastPaymentsCount + 1,
                    ]);

                    $newStatus = ArService::deriveInvoiceStatus(
                        $inv->status,
                        $newAmountPaid,
                        (float) $inv->total,
                        $inv->due_date->format('Y-m-d')
                    );

                    $inv->update([
                        'amount_paid' => $newAmountPaid,
                        'balance_due' => $newBalanceDue,
                        'status' => $newStatus,
                    ]);

                    $updatedInvoices[] = ArTransformer::invoice($inv->fresh(['customer', 'details', 'installments', 'createdBy']));
                }
            }

            $dpTransaction = null;
            if ($overpaymentToDeposit > 0) {
                $newDpBalance = (float) $customer->dp_balance + $overpaymentToDeposit;
                $customer->update(['dp_balance' => $newDpBalance]);

                $dpTx = CustomerDpTransaction::create([
                    'customer_id' => $customer->id,
                    'payment_id' => $payment->id,
                    'transaction_date' => $paymentDate,
                    'type' => 'deposit',
                    'amount' => $overpaymentToDeposit,
                    'balance_after' => $newDpBalance,
                    'reference_number' => $payment->reference_number,
                    'notes' => "Kelebihan bayar tagihan invoice dialokasikan otomatis ke Saldo Deposit (Ref: {$payment->reference_number})",
                    'created_by_id' => $user->id,
                ]);
                $dpTransaction = ArTransformer::dpTransaction($dpTx);
            }

            $formattedAmount = number_format($amount, 0, ',', '.');
            $attachSuffix = $payment->attachment_name ? ' [Dilampirkan Bukti Fisik Slip]' : '';

            $log = ArService::logActivity([
                'type' => 'payment_recorded',
                'description' => "Pembayaran {$payment->payment_number} sebesar Rp {$formattedAmount} dicatat via {$payment->payment_channel} ({$customer->name}){$attachSuffix}",
                'amount' => $amount,
                'reference_id' => $payment->payment_number,
                'badge_type' => 'success',
            ], $request);

            return response()->json([
                'success' => true,
                'payment' => ArTransformer::payment($payment->fresh(['customer', 'allocations.invoice'])),
                'updatedInvoices' => $updatedInvoices,
                'customer' => ArTransformer::customer($customer->fresh()),
                'dpTransaction' => $dpTransaction,
                'activityLog' => ArTransformer::activityLog($log),
            ], 201);
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $payment = Payment::with('allocations')->find($id);
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Pembayaran tidak ditemukan.'], 404);
        }

        return DB::transaction(function () use ($request, $payment) {
            $updatedInvoices = [];

            foreach ($payment->allocations as $alloc) {
                $inv = Invoice::find($alloc->invoice_id);
                if ($inv) {
                    $restoredPaid = max(0, (float) $inv->amount_paid - (float) $alloc->allocated_amount);
                    $restoredBalance = min((float) $inv->total, (float) $inv->balance_due + (float) $alloc->allocated_amount);
                    $newStatus = ArService::deriveInvoiceStatus(
                        $inv->status === 'Paid' ? 'Issued' : $inv->status,
                        $restoredPaid,
                        (float) $inv->total,
                        $inv->due_date->format('Y-m-d')
                    );

                    $inv->update([
                        'amount_paid' => $restoredPaid,
                        'balance_due' => $restoredBalance,
                        'status' => $newStatus,
                    ]);

                    $updatedInvoices[] = ArTransformer::invoice($inv->fresh(['customer', 'details', 'installments', 'createdBy']));
                }
            }

            $paymentNumber = $payment->payment_number;
            $amount = (float) $payment->amount;
            $formattedAmount = number_format($amount, 0, ',', '.');

            $payment->allocations()->delete();
            $payment->delete();

            $log = ArService::logActivity([
                'type' => 'payment_deleted',
                'description' => "Pembayaran {$paymentNumber} (Rp {$formattedAmount}) dibatalkan / di-rollback",
                'amount' => $amount,
                'reference_id' => $paymentNumber,
                'badge_type' => 'danger',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => "Pembayaran {$paymentNumber} berhasil di-rollback dan saldo tagihan invoice dipulihkan.",
                'updatedInvoices' => $updatedInvoices,
                'activityLog' => ArTransformer::activityLog($log),
            ]);
        });
    }

    public function verifyAudit(Request $request, string $id): JsonResponse
    {
        $payment = Payment::with('customer')->find($id);
        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Pembayaran tidak ditemukan.'], 404);
        }

        $user = ArService::resolveActiveUser($request);
        $auditorName = $request->input('auditorName') ?: $user->full_name;
        $notes = $request->input('notes') ?: 'Telah diverifikasi fisik dan rekonsiliasi kas bank oleh Dept FA';

        $payment->update([
            'audit_status' => 'verified_fa',
            'audited_by_id' => $user->id,
            'audited_by_name' => $auditorName,
            'audited_at' => ArService::TODAY . ' ' . now()->format('H:i:s'),
            'audit_notes' => $notes,
        ]);

        $customerName = $payment->customer?->name ?? 'Pelanggan';
        $formattedAmount = number_format((float) $payment->amount, 0, ',', '.');

        $log = ArService::logActivity([
            'type' => 'status_changed',
            'description' => "Income Audit: Pembayaran {$payment->payment_number} ({$customerName}, Rp {$formattedAmount}) diverifikasi fisik oleh {$auditorName}",
            'amount' => $payment->amount,
            'reference_id' => $payment->payment_number,
            'badge_type' => 'success',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Pembayaran {$payment->payment_number} berhasil diverifikasi oleh Income Audit FA!",
            'payment' => ArTransformer::payment($payment->fresh(['customer', 'allocations.invoice'])),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }
}
