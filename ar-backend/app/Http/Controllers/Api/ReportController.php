<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function dashboardMetrics(): JsonResponse
    {
        $openInvoices = Invoice::whereNotIn('status', ['Cancelled', 'Paid', 'Draft'])->get();

        $totalOutstanding = (float) $openInvoices->sum('balance_due');
        $openCount = $openInvoices->count();

        $overdueInvoices = $openInvoices->where('status', 'Overdue');
        $totalOverdue = (float) $overdueInvoices->sum('balance_due');
        $overdueCount = $overdueInvoices->count();

        $monthPrefix = substr(ArService::TODAY, 0, 7); // 2026-09
        $monthPayments = Payment::where('payment_date', 'like', "{$monthPrefix}%")->get();

        return response()->json([
            'totalOutstanding' => $totalOutstanding,
            'totalOverdue' => $totalOverdue,
            'collectedThisMonth' => (float) $monthPayments->sum('amount'),
            'openInvoicesCount' => $openCount,
            'overdueInvoicesCount' => $overdueCount,
            'paidThisMonthCount' => $monthPayments->count(),
        ]);
    }

    public function aging(Request $request): JsonResponse
    {
        $asOfDate = $request->input('asOfDate', ArService::TODAY);
        $asOfTime = strtotime($asOfDate);

        $customers = Customer::orderBy('id')->get();
        $invoices = Invoice::with(['customer', 'details', 'installments', 'createdBy'])
            ->whereNotIn('status', ['Cancelled', 'Draft'])
            ->where('balance_due', '>', 0)
            ->get();

        $bucketMap = [];
        foreach ($customers as $c) {
            $bucketMap[$c->id] = [
                'customerId' => (string) $c->id,
                'customerCode' => $c->code,
                'customerName' => $c->name,
                'current' => 0.0,
                'days1_30' => 0.0,
                'days31_60' => 0.0,
                'days61_90' => 0.0,
                'days90Plus' => 0.0,
                'totalOutstanding' => 0.0,
                'invoices' => [],
            ];
        }

        foreach ($invoices as $inv) {
            $cid = $inv->customer_id;
            if (!isset($bucketMap[$cid])) {
                $bucketMap[$cid] = [
                    'customerId' => (string) $cid,
                    'customerCode' => 'CUST-?',
                    'customerName' => $inv->customer?->name ?? 'Unknown',
                    'current' => 0.0,
                    'days1_30' => 0.0,
                    'days31_60' => 0.0,
                    'days61_90' => 0.0,
                    'days90Plus' => 0.0,
                    'totalOutstanding' => 0.0,
                    'invoices' => [],
                ];
            }

            $bucketMap[$cid]['invoices'][] = ArTransformer::invoice($inv);

            $dueTime = strtotime($inv->due_date->format('Y-m-d'));
            $diffDays = (int) floor(($asOfTime - $dueTime) / 86400);
            $bal = (float) $inv->balance_due;

            if ($diffDays <= 0) {
                $bucketMap[$cid]['current'] += $bal;
            } elseif ($diffDays <= 30) {
                $bucketMap[$cid]['days1_30'] += $bal;
            } elseif ($diffDays <= 60) {
                $bucketMap[$cid]['days31_60'] += $bal;
            } elseif ($diffDays <= 90) {
                $bucketMap[$cid]['days61_90'] += $bal;
            } else {
                $bucketMap[$cid]['days90Plus'] += $bal;
            }

            $bucketMap[$cid]['totalOutstanding'] += $bal;
        }

        $buckets = array_values(array_filter($bucketMap, fn ($b) => $b['totalOutstanding'] > 0));

        $grandTotal = [
            'customerId' => 'total',
            'customerCode' => 'TOTAL',
            'customerName' => 'Grand Total',
            'current' => array_sum(array_column($buckets, 'current')),
            'days1_30' => array_sum(array_column($buckets, 'days1_30')),
            'days31_60' => array_sum(array_column($buckets, 'days31_60')),
            'days61_90' => array_sum(array_column($buckets, 'days61_90')),
            'days90Plus' => array_sum(array_column($buckets, 'days90Plus')),
            'totalOutstanding' => array_sum(array_column($buckets, 'totalOutstanding')),
        ];

        return response()->json([
            'buckets' => $buckets,
            'grandTotal' => $grandTotal,
        ]);
    }

    public function mutasiSummary(Request $request): JsonResponse
    {
        $startDate = $request->input('startDate', '2026-01-01');
        $endDate = $request->input('endDate', '2026-09-29');

        $customers = Customer::orderBy('id')->get();
        $invoices = Invoice::whereNotIn('status', ['Draft', 'Cancelled'])
            ->when($startDate, fn ($q) => $q->whereDate('issue_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('issue_date', '<=', $endDate))
            ->get();

        $payments = Payment::when($startDate, fn ($q) => $q->whereDate('payment_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('payment_date', '<=', $endDate))
            ->get();

        $list = [];
        foreach ($customers as $c) {
            $cInvs = $invoices->where('customer_id', $c->id);
            $cPays = $payments->where('customer_id', $c->id);

            $totalPiutang = (float) $cInvs->sum('total');
            $piutangDibayar = (float) $cPays->sum('amount');
            $txCount = $cInvs->count() + $cPays->count();
            $saldo = max(0, $totalPiutang - $piutangDibayar);

            if ($totalPiutang > 0 || $piutangDibayar > 0 || $saldo > 0) {
                $list[] = [
                    'customerId' => (string) $c->id,
                    'customerName' => $c->name,
                    'transactionCount' => $txCount ?: ($cInvs->count() > 0 ? $cInvs->count() : 1),
                    'totalPiutang' => $totalPiutang,
                    'piutangDibayar' => $piutangDibayar,
                    'saldo' => $saldo,
                ];
            }
        }

        $grandTotal = [
            'count' => array_sum(array_column($list, 'transactionCount')),
            'totalPiutang' => array_sum(array_column($list, 'totalPiutang')),
            'piutangDibayar' => array_sum(array_column($list, 'piutangDibayar')),
            'saldo' => array_sum(array_column($list, 'saldo')),
        ];

        return response()->json([
            'list' => $list,
            'grandTotal' => $grandTotal,
        ]);
    }

    public function mutasiDetail(Request $request, string $customerId): JsonResponse
    {
        $startDate = $request->input('startDate', '2026-01-01');
        $endDate = $request->input('endDate', '2026-09-29');

        $customer = Customer::find($customerId);
        $customerName = $customer?->name ?? 'Pelanggan';
        $customerCode = $customer?->code ?? '114.120';

        $invoices = Invoice::where('customer_id', $customerId)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->when($startDate, fn ($q) => $q->whereDate('issue_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('issue_date', '<=', $endDate))
            ->get();

        $payments = Payment::with('allocations.invoice')
            ->where('customer_id', $customerId)
            ->when($startDate, fn ($q) => $q->whereDate('payment_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('payment_date', '<=', $endDate))
            ->get();

        $combined = [];
        foreach ($invoices as $inv) {
            $combined[] = [
                'date' => $inv->issue_date->format('Y-m-d'),
                'id' => (string) $inv->id,
                'noInvoice' => $inv->invoice_number,
                'noKwitansi' => '-',
                'keterangan' => $inv->notes ?: $inv->invoice_type,
                'debet' => (float) $inv->total,
                'kredit' => 0.0,
                'type' => 'invoice',
            ];
        }

        foreach ($payments as $pay) {
            $firstAlloc = $pay->allocations->first();
            $invRef = $firstAlloc?->invoice?->invoice_number ?? '-';

            $combined[] = [
                'date' => $pay->payment_date->format('Y-m-d'),
                'id' => (string) $pay->id,
                'noInvoice' => $invRef,
                'noKwitansi' => $pay->kwitansi_number ?: ($pay->reference_number ?: '-'),
                'keterangan' => $pay->notes ?: "Payment for invoice {$invRef}",
                'debet' => 0.0,
                'kredit' => (float) $pay->amount,
                'type' => 'payment',
            ];
        }

        usort($combined, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $runningBalance = 0.0;
        $details = [];
        foreach ($combined as $item) {
            $runningBalance += $item['debet'] - $item['kredit'];
            $details[] = [
                'id' => $item['id'],
                'tanggal' => $item['date'],
                'noInvoice' => $item['noInvoice'],
                'noKwitansi' => $item['noKwitansi'],
                'keterangan' => $item['keterangan'],
                'piutang' => $item['debet'],
                'terbayar' => $item['kredit'],
                'saldo' => max(0, $runningBalance),
                'type' => $item['type'],
            ];
        }

        $totalPiutang = array_sum(array_column($details, 'piutang'));
        $totalTerbayar = array_sum(array_column($details, 'terbayar'));

        return response()->json([
            'customerCode' => $customerCode,
            'customerName' => $customerName,
            'details' => $details,
            'totalPiutang' => $totalPiutang,
            'totalTerbayar' => $totalTerbayar,
            'saldoAkhir' => max(0, $totalPiutang - $totalTerbayar),
        ]);
    }

    public function kartuPiutang(Request $request, string $customerId): JsonResponse
    {
        $startDate = $request->input('startDate', '2026-08-01');
        $endDate = $request->input('endDate', '2026-09-29');

        $invoices = Invoice::with(['penalty', 'allocations.payment'])
            ->where('customer_id', $customerId)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->when($startDate, fn ($q) => $q->whereDate('issue_date', '>=', $startDate))
            ->when($endDate, fn ($q) => $q->whereDate('issue_date', '<=', $endDate))
            ->orderBy('issue_date')
            ->get();

        $asOfTime = strtotime(ArService::TODAY);
        $rows = [];

        foreach ($invoices as $inv) {
            $matchingAlloc = $inv->allocations->first();
            $matchingPay = $matchingAlloc?->payment;

            $isPaid = $inv->status === 'Paid';
            $paidNominal = $isPaid ? (float) $inv->total : ($matchingPay ? (float) $matchingPay->amount : 0.0);
            $saldo = (float) $inv->balance_due;

            $dueTime = strtotime($inv->due_date->format('Y-m-d'));
            $diffDays = max(0, (int) floor(($asOfTime - $dueTime) / 86400));

            $penalty = $inv->penalty;
            $hasDenda = $inv->status === 'Overdue' || $inv->times_overdue > 0;
            $nominalDenda = $penalty ? (float) $penalty->penalty_amount : ($hasDenda ? 50000.0 : 0.0);
            $saldoDenda = $penalty ? (float) $penalty->balance_due : $nominalDenda;

            $issueStr = $inv->issue_date->format('Y-m-d');
            $dueStr = $inv->due_date->format('Y-m-d');
            $payDateStr = $matchingPay ? $matchingPay->payment_date->format('Y-m-d') : ($isPaid ? $dueStr : $issueStr);

            $rows[] = [
                'tglTerbit' => $issueStr,
                'noInvoice' => $inv->invoice_number,
                'noBuktiJurnalDebet' => $inv->journal_number ?: ('FN' . substr(str_replace('-', '', $issueStr), 2) . '008'),
                'keteranganDebet' => "Invoice {$inv->invoice_number}",
                'nominalDebet' => (float) $inv->total,
                'tglJatuhTempo' => $dueStr,

                'tglBayar' => $payDateStr,
                'noBuktiJurnalKredit' => $matchingPay
                    ? ($matchingPay->journal_number ?: ('BD' . substr(str_replace('-', '', $payDateStr), 2) . '001'))
                    : '-',
                'keteranganKredit' => $matchingPay
                    ? "Payment for Invoice {$inv->invoice_number}"
                    : "No Payment for Invoice {$inv->invoice_number}",
                'nominalKredit' => $paidNominal,
                'diskon' => $matchingPay ? (float) $matchingPay->discount_amount : 0.0,
                'saldoPiutang' => $saldo,
                'umurPiutang' => $diffDays,

                'nominalDenda' => $nominalDenda,
                'tglBayarDenda' => $penalty?->paid_date ? $penalty->paid_date->format('Y-m-d') : '-',
                'noBuktiJurnalDenda' => $penalty?->payment_journal_number ?: '-',
                'keteranganDenda' => $penalty?->payment_notes ?: '-',
                'nominalBayarDenda' => $penalty ? (float) $penalty->paid_amount : 0.0,
                'tglHapusDenda' => $penalty?->waived_date ? $penalty->waived_date->format('Y-m-d') : '-',
                'memoHapusDenda' => $penalty?->waiver_memo_number ?: '-',
                'keteranganHapusDenda' => $penalty?->waiver_notes ?: '-',
                'nominalHapusDenda' => $penalty ? (float) $penalty->waived_amount : 0.0,
                'saldoDenda' => $saldoDenda,
            ];
        }

        return response()->json($rows);
    }
}
