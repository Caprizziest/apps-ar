<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InvoiceInstallment;
use App\Models\InvoicePenalty;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        $invoices = Invoice::with(['customer', 'details', 'installments', 'createdBy'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Invoice $inv) => ArTransformer::invoice($inv));

        return response()->json($invoices);
    }

    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'details', 'installments', 'createdBy'])->find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        return response()->json(ArTransformer::invoice($invoice));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = Customer::find($request->input('customerId'));
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Pelanggan tidak ditemukan.'], 422);
        }

        $user = ArService::resolveActiveUser($request);

        return DB::transaction(function () use ($request, $customer, $user) {
            $rawItems = $request->input('lineItems', []);
            $calculatedItems = [];
            $subtotal = 0;

            foreach ($rawItems as $item) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unitPrice'] ?? 0);
                $lineTotal = round($qty * $unitPrice);
                $subtotal += $lineTotal;

                $calculatedItems[] = [
                    'description' => $item['description'] ?? 'Layanan Hotel',
                    'item_type' => $item['itemType'] ?? 'Service',
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $taxRate = (float) $request->input('taxRate', 0);
            $pphRate = (float) $request->input('pphRate', 0);
            $dpDeduction = (float) $request->input('dpDeduction', 0);

            $taxAmount = round(($subtotal * $taxRate) / 100);
            $pphAmount = round(($subtotal * $pphRate) / 100);
            $baseTotal = max(0, $subtotal + $taxAmount - $pphAmount - $dpDeduction);

            $hasRollover = (bool) $request->input('hasRollover', false);
            $rolledOverAmount = $hasRollover ? (float) $request->input('rolledOverAmount', 0) : 0;
            $total = $baseTotal + $rolledOverAmount;

            $saveAs = $request->input('saveAs', 'Issued');
            $issueDate = $request->input('issueDate', ArService::TODAY);
            $dueDate = $request->input('dueDate', ArService::TODAY);

            $initialStatus = $saveAs === 'Draft' ? 'Draft' : 'Issued';
            if ($initialStatus === 'Issued') {
                $initialStatus = ArService::deriveInvoiceStatus('Issued', 0, $total, $dueDate);
            }

            $journalNumber = $request->input('journalNumber')
                ?: ('FN' . substr(str_replace('-', '', $issueDate), 2) . random_int(100, 999));

            $invoice = Invoice::create([
                'invoice_number' => ArService::nextInvoiceNumber(),
                'journal_number' => $journalNumber,
                'customer_id' => $customer->id,
                'customer_nik' => $customer->nik,
                'customer_npwp' => $customer->npwp,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'status' => $initialStatus,
                'invoice_type' => $request->input('invoiceType', 'Standard Hospitality Invoice'),
                'source_type' => $request->input('sourceType', 'generic'),
                'company_unit' => $customer->company_unit ?? 'PT. GALESONG PRATAMA HOTEL',
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'pph_rate' => $pphRate,
                'pph_amount' => $pphAmount,
                'dp_deduction' => $dpDeduction,
                'total' => $total,
                'amount_paid' => 0,
                'balance_due' => $total,
                'notes' => $request->input('notes', ''),
                'is_installment' => (bool) $request->input('isInstallment', false),
                'installment_count' => $request->input('installmentCount'),
                'installment_interval_days' => $request->input('installmentIntervalDays'),
                'times_overdue' => (int) $request->input('timesOverdue', 0),
                'has_rollover' => $hasRollover,
                'rolled_over_amount' => $rolledOverAmount,
                'rolled_over_from' => $request->input('rolledOverFrom'),
                'created_by_id' => $user->id,
            ]);

            foreach ($calculatedItems as $cItem) {
                InvoiceDetail::create(array_merge($cItem, ['invoice_id' => $invoice->id]));
            }

            $schedule = $request->input('installmentSchedule', []);
            if (is_array($schedule)) {
                foreach ($schedule as $inst) {
                    InvoiceInstallment::create([
                        'invoice_id' => $invoice->id,
                        'installment_number' => (int) ($inst['installmentNumber'] ?? 1),
                        'due_date' => $inst['dueDate'] ?? $dueDate,
                        'amount' => (float) ($inst['amount'] ?? 0),
                        'amount_paid' => (float) ($inst['amountPaid'] ?? 0),
                        'status' => $inst['status'] ?? 'Unpaid',
                        'paid_date' => $inst['paidDate'] ?? null,
                    ]);
                }
            }

            $formattedTotal = number_format($total, 0, ',', '.');
            $desc = "Invoice {$invoice->invoice_number} ({$invoice->status}) dibuat untuk {$customer->name} (Total Rp {$formattedTotal})";
            if ($invoice->is_installment) {
                $desc .= " - Skema Cicilan {$invoice->installment_count}x";
            }
            if ($hasRollover) {
                $formattedRollover = number_format($rolledOverAmount, 0, ',', '.');
                $desc .= " (Termasuk tunggakan Rp {$formattedRollover})";
            }

            $log = ArService::logActivity([
                'type' => $invoice->status === 'Draft' ? 'invoice_created' : 'invoice_issued',
                'description' => $desc,
                'amount' => $total,
                'reference_id' => $invoice->invoice_number,
                'badge_type' => $invoice->status === 'Draft' ? 'secondary' : 'info',
            ], $request);

            return response()->json([
                'success' => true,
                'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'createdBy'])),
                'activityLog' => ArTransformer::activityLog($log),
            ], 201);
        });
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($invoice->status !== 'Draft') {
            return response()->json([
                'success' => false,
                'message' => 'Perubahan isi invoice hanya diperkenankan pada invoice berstatus Draft.',
            ], 422);
        }

        $issueDate = $request->input('issueDate', $invoice->issue_date->format('Y-m-d'));
        $dueDate = $request->input('dueDate', $invoice->due_date->format('Y-m-d'));

        if ($dueDate < $issueDate) {
            return response()->json([
                'success' => false,
                'message' => 'Tanggal jatuh tempo tidak boleh lebih awal dari tanggal invoice.',
            ], 422);
        }

        $customer = Customer::find($request->input('customerId', $invoice->customer_id));

        return DB::transaction(function () use ($request, $invoice, $customer, $issueDate, $dueDate) {
            $rawItems = $request->input('lineItems', []);
            $calculatedItems = [];
            $subtotal = 0;

            foreach ($rawItems as $item) {
                $qty = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unitPrice'] ?? 0);
                $lineTotal = round($qty * $unitPrice);
                $subtotal += $lineTotal;

                $calculatedItems[] = [
                    'description' => $item['description'] ?? 'Layanan Hotel',
                    'item_type' => $item['itemType'] ?? 'Service',
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $taxRate = (float) $request->input('taxRate', $invoice->tax_rate);
            $pphRate = (float) $request->input('pphRate', $invoice->pph_rate);
            $dpDeduction = (float) $request->input('dpDeduction', $invoice->dp_deduction);

            $taxAmount = round(($subtotal * $taxRate) / 100);
            $pphAmount = round(($subtotal * $pphRate) / 100);
            $baseTotal = max(0, $subtotal + $taxAmount - $pphAmount - $dpDeduction);

            $hasRollover = (bool) $request->input('hasRollover', $invoice->has_rollover);
            $rolledOverAmount = $hasRollover ? (float) $request->input('rolledOverAmount', $invoice->rolled_over_amount) : 0;
            $total = $baseTotal + $rolledOverAmount;

            $invoice->update([
                'customer_id' => $customer?->id ?? $invoice->customer_id,
                'customer_nik' => $customer?->nik ?? $invoice->customer_nik,
                'customer_npwp' => $customer?->npwp ?? $invoice->customer_npwp,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'invoice_type' => $request->input('invoiceType', $invoice->invoice_type),
                'source_type' => $request->input('sourceType', $invoice->source_type),
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'pph_rate' => $pphRate,
                'pph_amount' => $pphAmount,
                'dp_deduction' => $dpDeduction,
                'total' => $total,
                'balance_due' => $total,
                'notes' => $request->input('notes', $invoice->notes),
                'is_installment' => (bool) $request->input('isInstallment', $invoice->is_installment),
                'installment_count' => $request->input('installmentCount', $invoice->installment_count),
                'installment_interval_days' => $request->input('installmentIntervalDays', $invoice->installment_interval_days),
                'has_rollover' => $hasRollover,
                'rolled_over_amount' => $rolledOverAmount,
                'rolled_over_from' => $request->input('rolledOverFrom', $invoice->rolled_over_from),
                'times_overdue' => (int) $request->input('timesOverdue', $invoice->times_overdue),
            ]);

            $invoice->details()->delete();
            foreach ($calculatedItems as $cItem) {
                InvoiceDetail::create(array_merge($cItem, ['invoice_id' => $invoice->id]));
            }

            if ($request->has('installmentSchedule')) {
                $invoice->installments()->delete();
                $schedule = $request->input('installmentSchedule', []);
                if (is_array($schedule)) {
                    foreach ($schedule as $inst) {
                        InvoiceInstallment::create([
                            'invoice_id' => $invoice->id,
                            'installment_number' => (int) ($inst['installmentNumber'] ?? 1),
                            'due_date' => $inst['dueDate'] ?? $dueDate,
                            'amount' => (float) ($inst['amount'] ?? 0),
                            'amount_paid' => (float) ($inst['amountPaid'] ?? 0),
                            'status' => $inst['status'] ?? 'Unpaid',
                            'paid_date' => $inst['paidDate'] ?? null,
                        ]);
                    }
                }
            }

            $formattedTotal = number_format($total, 0, ',', '.');
            $log = ArService::logActivity([
                'type' => 'invoice_updated',
                'description' => "Rincian invoice Draft {$invoice->invoice_number} diperbarui (Total baru Rp {$formattedTotal})",
                'amount' => $total,
                'reference_id' => $invoice->invoice_number,
                'badge_type' => 'secondary',
            ], $request);

            return response()->json([
                'success' => true,
                'message' => "Invoice Draft {$invoice->invoice_number} berhasil diperbarui!",
                'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'createdBy'])),
                'activityLog' => ArTransformer::activityLog($log),
            ]);
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($invoice->status !== 'Draft') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya invoice berstatus Draft yang dapat dihapus secara permanen.',
            ], 422);
        }

        $user = ArService::resolveActiveUser($request);
        $invNumber = $invoice->invoice_number;
        $invoice->forceDelete();

        $log = ArService::logActivity([
            'type' => 'invoice_deleted',
            'description' => "Invoice Draft {$invNumber} dihapus oleh {$user->full_name}",
            'reference_id' => $invNumber,
            'badge_type' => 'danger',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Invoice Draft {$invNumber} berhasil dihapus.",
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }

    public function issue(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with('customer')->find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($invoice->status !== 'Draft') {
            return response()->json([
                'success' => false,
                'message' => "Invoice sudah berstatus {$invoice->status} dan tidak dalam status Draft.",
            ], 422);
        }

        if ($invoice->subtotal <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal: Subtotal invoice tidak boleh bernilai nol untuk diterbitkan.',
            ], 422);
        }

        $newStatus = ArService::deriveInvoiceStatus(
            'Issued',
            (float) $invoice->amount_paid,
            (float) $invoice->total,
            $invoice->due_date->format('Y-m-d')
        );

        $invoice->update(['status' => $newStatus]);

        $user = ArService::resolveActiveUser($request);
        $customerName = $invoice->customer?->name ?? 'Pelanggan';

        $log = ArService::logActivity([
            'type' => 'invoice_issued',
            'description' => "Invoice {$invoice->invoice_number} resmi diterbitkan kepada {$customerName} oleh {$user->full_name}",
            'amount' => $invoice->total,
            'reference_id' => $invoice->invoice_number,
            'badge_type' => 'info',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Invoice {$invoice->invoice_number} berhasil diterbitkan dan siap ditagihkan.",
            'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'createdBy'])),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        if ($invoice->status === 'Cancelled') {
            return response()->json(['success' => false, 'message' => 'Invoice ini telah dibatalkan sebelumnya.'], 422);
        }

        if ($invoice->amount_paid > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice Memiliki Pembayaran: Invoice yang telah memiliki pembayaran teralokasi tidak dapat dibatalkan.',
            ], 422);
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            return response()->json(['success' => false, 'message' => 'Pengisian alasan pembatalan bersifat wajib.'], 422);
        }

        $user = ArService::resolveActiveUser($request);

        $invoice->update([
            'status' => 'Cancelled',
            'balance_due' => 0,
            'cancellation_reason' => $reason,
            'cancelled_at' => ArService::TODAY . ' ' . now()->format('H:i:s'),
            'cancelled_by_id' => $user->id,
            'cancelled_by_name' => $user->full_name,
        ]);

        $log = ArService::logActivity([
            'type' => 'invoice_cancelled',
            'description' => "Invoice {$invoice->invoice_number} dibatalkan oleh {$user->full_name}. Alasan: \"{$reason}\"",
            'reference_id' => $invoice->invoice_number,
            'badge_type' => 'danger',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Invoice {$invoice->invoice_number} berhasil dibatalkan.",
            'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'createdBy'])),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }

    public function detectOverdue(Request $request): JsonResponse
    {
        $today = $request->input('asOfDate', ArService::TODAY);

        $candidates = Invoice::whereIn('status', ['Issued', 'Partially Paid'])
            ->where('balance_due', '>', 0)
            ->whereDate('due_date', '<', $today)
            ->get();

        $overdueFound = 0;
        foreach ($candidates as $inv) {
            $inv->update([
                'status' => 'Overdue',
                'times_overdue' => max(1, (int) $inv->times_overdue + 1),
            ]);
            $overdueFound++;
        }

        $log = null;
        if ($overdueFound > 0) {
            $log = ArService::logActivity([
                'type' => 'status_changed',
                'description' => "Scheduled Overdue Detection: {$overdueFound} invoice diperbarui statusnya menjadi Overdue",
                'badge_type' => 'warning',
            ], $request);
        }

        return response()->json([
            'success' => true,
            'overdueFound' => $overdueFound,
            'activityLog' => $log ? ArTransformer::activityLog($log) : null,
        ]);
    }

    public function payPenalty(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'penalty'])->find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        $paidAmount = (float) $request->input('paidAmount', 0);
        if ($paidAmount <= 0) {
            return response()->json(['success' => false, 'message' => 'Nominal pembayaran denda harus lebih dari 0.'], 422);
        }

        $paidDate = $request->input('paidDate', ArService::TODAY);
        $journalNumber = $request->input('paymentJournalNumber')
            ?: ('BD' . substr(str_replace('-', '', $paidDate), 2) . random_int(100, 999));
        $notes = $request->input('paymentNotes') ?: "Pelunasan denda faktur {$invoice->invoice_number}";

        $penalty = $invoice->penalty;
        $basePenalty = $penalty ? (float) $penalty->penalty_amount : 50000.0;
        $prevPaid = $penalty ? (float) $penalty->paid_amount : 0.0;
        $prevWaived = $penalty ? (float) $penalty->waived_amount : 0.0;

        $newPaidTotal = min($basePenalty - $prevWaived, $prevPaid + $paidAmount);
        $newBalance = max(0, $basePenalty - $newPaidTotal - $prevWaived);

        $penalty = InvoicePenalty::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'penalty_amount' => $basePenalty,
                'paid_date' => $paidDate,
                'payment_journal_number' => $journalNumber,
                'payment_notes' => $notes,
                'paid_amount' => $newPaidTotal,
                'balance_due' => $newBalance,
            ]
        );

        $formattedAmount = number_format($paidAmount, 0, ',', '.');
        $log = ArService::logActivity([
            'type' => 'payment_recorded',
            'description' => "Pembayaran Denda sebesar Rp {$formattedAmount} dicatat untuk invoice {$invoice->invoice_number} (Jurnal: {$journalNumber})",
            'amount' => $paidAmount,
            'reference_id' => $invoice->invoice_number,
            'badge_type' => 'success',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Pembayaran denda Rp {$formattedAmount} untuk {$invoice->invoice_number} berhasil dicatat!",
            'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'penalty', 'createdBy'])),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }

    public function waivePenalty(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'penalty'])->find($id);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice tidak ditemukan.'], 404);
        }

        $waivedAmount = (float) $request->input('waivedAmount', 0);
        if ($waivedAmount <= 0) {
            return response()->json(['success' => false, 'message' => 'Nominal penghapusan denda harus lebih dari 0.'], 422);
        }

        $waivedDate = $request->input('waivedDate', ArService::TODAY);
        $memoNumber = trim((string) $request->input('waiverMemoNumber', ''));
        if ($memoNumber === '') {
            $memoNumber = 'MEMO/FNC/' . substr(str_replace('-', '', $waivedDate), 2) . '/' . random_int(10, 99);
        }
        $notes = $request->input('waiverNotes') ?: "Penghapusan denda faktur {$invoice->invoice_number}";
        $user = ArService::resolveActiveUser($request);

        $penalty = $invoice->penalty;
        $basePenalty = $penalty ? (float) $penalty->penalty_amount : 50000.0;
        $prevPaid = $penalty ? (float) $penalty->paid_amount : 0.0;
        $prevWaived = $penalty ? (float) $penalty->waived_amount : 0.0;

        $newWaivedTotal = min($basePenalty - $prevPaid, $prevWaived + $waivedAmount);
        $newBalance = max(0, $basePenalty - $prevPaid - $newWaivedTotal);

        InvoicePenalty::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'penalty_amount' => $basePenalty,
                'waived_date' => $waivedDate,
                'waiver_memo_number' => $memoNumber,
                'waiver_notes' => $notes,
                'waived_amount' => $newWaivedTotal,
                'waived_by_id' => $user->id,
                'balance_due' => $newBalance,
            ]
        );

        $formattedAmount = number_format($waivedAmount, 0, ',', '.');
        $log = ArService::logActivity([
            'type' => 'status_changed',
            'description' => "Penghapusan Denda sebesar Rp {$formattedAmount} disetujui untuk invoice {$invoice->invoice_number} (Memo: {$memoNumber})",
            'amount' => $waivedAmount,
            'reference_id' => $invoice->invoice_number,
            'badge_type' => 'warning',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Penghapusan denda Rp {$formattedAmount} (Memo: {$memoNumber}) berhasil diterapkan!",
            'invoice' => ArTransformer::invoice($invoice->fresh(['customer', 'details', 'installments', 'penalty', 'createdBy'])),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }
}
