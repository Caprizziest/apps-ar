<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDpTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DpTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CustomerDpTransaction::with(['customer', 'invoice', 'payment'])->orderByDesc('id');
        if ($request->filled('customerId')) {
            $query->where('customer_id', $request->input('customerId'));
        }

        $list = $query->get()->map(fn (CustomerDpTransaction $tx) => ArTransformer::dpTransaction($tx));

        return response()->json($list);
    }

    public function deposit(Request $request): JsonResponse
    {
        $customer = Customer::find($request->input('customerId'));
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
        }

        $amount = (float) $request->input('amount', 0);
        if ($amount <= 0) {
            return response()->json(['success' => false, 'message' => 'Nominal setoran DP harus lebih besar dari 0.'], 422);
        }

        $user = ArService::resolveActiveUser($request);

        return DB::transaction(function () use ($request, $customer, $amount, $user) {
            $newBalance = (float) $customer->dp_balance + $amount;
            $customer->update(['dp_balance' => $newBalance]);

            $paymentNumber = ArService::nextPaymentNumber();
            $today = ArService::TODAY;

            $payment = Payment::create([
                'payment_number' => $paymentNumber,
                'journal_number' => 'BD' . substr(str_replace('-', '', $today), 2) . '009',
                'kwitansi_number' => random_int(500, 999) . '/KLBR-FNC/MKS/DP/2026',
                'customer_id' => $customer->id,
                'payment_date' => $today,
                'amount' => $amount,
                'method' => $request->input('method', 'bank_transfer'),
                'payment_channel' => $request->input('paymentChannel', 'BCA Hotel'),
                'reference_number' => $request->input('referenceNumber', ''),
                'notes' => 'Setoran Uang Muka (DP): ' . $request->input('notes', ''),
                'is_down_payment' => true,
                'audit_status' => 'pending_fa',
                'attachment_name' => $request->input('attachmentName'),
                'attachment_size' => $request->input('attachmentSize'),
                'attachment_url' => $request->input('attachmentUrl'),
                'created_by_id' => $user->id,
            ]);

            $tx = CustomerDpTransaction::create([
                'customer_id' => $customer->id,
                'payment_id' => $payment->id,
                'transaction_date' => $today,
                'type' => 'deposit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_number' => $request->input('referenceNumber', ''),
                'notes' => $request->input('notes', 'Setoran uang muka'),
                'created_by_id' => $user->id,
            ]);

            $formattedAmount = number_format($amount, 0, ',', '.');
            $formattedBalance = number_format($newBalance, 0, ',', '.');
            $attachSuffix = $payment->attachment_name ? ' [Dilampirkan Bukti Fisik Slip]' : '';

            $log = ArService::logActivity([
                'type' => 'payment_recorded',
                'description' => "Uang Muka (DP) sebesar Rp {$formattedAmount} diterima dari {$customer->name}. Saldo DP kini: Rp {$formattedBalance}{$attachSuffix}",
                'amount' => $amount,
                'reference_id' => $paymentNumber,
                'badge_type' => 'success',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => "Setoran Uang Muka Rp {$formattedAmount} berhasil dicatat!",
                'transaction' => ArTransformer::dpTransaction($tx->fresh(['customer', 'invoice', 'payment'])),
                'payment' => ArTransformer::payment($payment->fresh(['customer', 'allocations.invoice'])),
                'customer' => ArTransformer::customer($customer->fresh()),
                'activityLog' => ArTransformer::activityLog($log),
            ], 201);
        });
    }

    public function apply(Request $request): JsonResponse
    {
        $customer = Customer::find($request->input('customerId'));
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
        }

        $invoice = Invoice::find($request->input('invoiceId'));
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        $amount = (float) $request->input('amount', 0);
        $currentBalance = (float) $customer->dp_balance;

        if ($amount > $currentBalance) {
            $formattedAvail = number_format($currentBalance, 0, ',', '.');
            return response()->json([
                'success' => false,
                'message' => "Saldo DP customer tidak mencukupi (Tersedia: Rp {$formattedAvail}).",
            ], 422);
        }

        if ($amount > (float) $invoice->balance_due) {
            $formattedDue = number_format((float) $invoice->balance_due, 0, ',', '.');
            return response()->json([
                'success' => false,
                'message' => "Nominal pemotongan DP melebihi sisa tagihan invoice (Sisa: Rp {$formattedDue}).",
            ], 422);
        }

        $user = ArService::resolveActiveUser($request);

        return DB::transaction(function () use ($request, $customer, $invoice, $amount, $currentBalance, $user) {
            $newDpBalance = $currentBalance - $amount;
            $customer->update(['dp_balance' => $newDpBalance]);

            $newDpDeduction = (float) $invoice->dp_deduction + $amount;
            $newBalanceDue = max(0, (float) $invoice->balance_due - $amount);
            $newTotal = max(0, (float) $invoice->total - $amount);

            $newStatus = ArService::deriveInvoiceStatus(
                $invoice->status,
                (float) $invoice->amount_paid,
                $newTotal,
                $invoice->due_date->format('Y-m-d')
            );
            if ($newBalanceDue <= 0) {
                $newStatus = 'Paid';
            }

            $invoice->update([
                'dp_deduction' => $newDpDeduction,
                'balance_due' => $newBalanceDue,
                'status' => $newStatus,
            ]);

            $tx = CustomerDpTransaction::create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
                'transaction_date' => ArService::TODAY,
                'type' => 'applied',
                'amount' => $amount,
                'balance_after' => $newDpBalance,
                'reference_number' => $invoice->invoice_number,
                'notes' => $request->input('notes') ?: "Pemotongan Saldo DP untuk faktur {$invoice->invoice_number}",
                'created_by_id' => $user->id,
            ]);

            $formattedAmount = number_format($amount, 0, ',', '.');

            $log = ArService::logActivity([
                'type' => 'invoice_updated',
                'description' => "Saldo DP sebesar Rp {$formattedAmount} dialokasikan untuk pemotongan invoice {$invoice->invoice_number} ({$customer->name})",
                'amount' => $amount,
                'reference_id' => $invoice->invoice_number,
                'badge_type' => 'info',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => "Berhasil memotong Rp {$formattedAmount} dari saldo DP untuk invoice {$invoice->invoice_number}.",
                'transaction' => ArTransformer::dpTransaction($tx->fresh(['customer', 'invoice', 'payment'])),
                'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'createdBy'])),
                'customer' => ArTransformer::customer($customer->fresh()),
                'activityLog' => ArTransformer::activityLog($log),
            ]);
        });
    }
}
