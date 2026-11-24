<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_bootstrap_endpoint_returns_all_ar_state_collections(): void
    {
        $response = $this->getJson('/api/bootstrap');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'today',
                'currentUser' => ['id', 'username', 'fullName', 'role'],
                'bankAccounts',
                'customers',
                'invoices',
                'payments',
                'dpTransactions',
                'activityLogs',
            ]);

        $this->assertCount(10, $response->json('customers'));
        $this->assertCount(22, $response->json('invoices'));
        $this->assertCount(12, $response->json('payments'));
        $this->assertCount(3, $response->json('bankAccounts'));
    }

    public function test_customer_crud_and_srs_f_03_deletion_protection(): void
    {
        // 1. Create new customer
        $createRes = $this->postJson('/api/customers', [
            'name' => 'PT Uji Coba Perhotelan',
            'contactPerson' => 'Andi Wijaya',
            'email' => 'andi@ujicoba.co.id',
            'phone' => '+62 811 2233 4455',
            'address' => 'Jl. Sudirman No. 10, Makassar',
            'nik' => '7371-0112-0384-0001',
            'npwp' => '01.234.567.8-901.000',
            'creditLimit' => 100000000,
            'dueDays' => 30,
            'status' => 'active',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('customer.name', 'PT Uji Coba Perhotelan')
            ->assertJsonPath('customer.nik', '7371011203840001');

        $newId = $createRes->json('customer.id');

        // 2. Update customer
        $this->putJson("/api/customers/{$newId}", [
            'status' => 'credit_hold',
            'creditLimit' => 120000000,
        ])->assertStatus(200)
            ->assertJsonPath('customer.status', 'credit_hold');

        // 3. Delete customer without invoices succeeds
        $this->deleteJson("/api/customers/{$newId}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // 4. Delete customer WITH invoices fails (SRS-F-03)
        $custWithInv = Customer::where('code', 'CUST-001')->firstOrFail();
        $this->deleteJson("/api/customers/{$custWithInv->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_invoice_draft_create_edit_issue_and_cancel_rules(): void
    {
        $customer = Customer::where('code', 'CUST-001')->firstOrFail();

        // 1. Create Draft Invoice
        $createRes = $this->postJson('/api/invoices', [
            'customerId' => (string) $customer->id,
            'issueDate' => '2026-09-16',
            'dueDate' => '2026-10-16',
            'saveAs' => 'Draft',
            'invoiceType' => 'Banquet & Event Billing',
            'taxRate' => 11,
            'pphRate' => 2,
            'dpDeduction' => 0,
            'lineItems' => [
                ['description' => 'Ballroom Package', 'itemType' => 'Banquet', 'quantity' => 1, 'unitPrice' => 50000000],
            ],
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('invoice.status', 'Draft')
            ->assertJsonPath('invoice.subtotal', 50000000)
            ->assertJsonPath('invoice.taxAmount', 5500000)
            ->assertJsonPath('invoice.pphAmount', 1000000)
            ->assertJsonPath('invoice.total', 54500000);

        $invId = $createRes->json('invoice.id');

        // 2. Update Draft Invoice (SRS-F-15)
        $this->putJson("/api/invoices/{$invId}", [
            'customerId' => (string) $customer->id,
            'issueDate' => '2026-09-16',
            'dueDate' => '2026-10-16',
            'taxRate' => 11,
            'pphRate' => 2,
            'dpDeduction' => 4500000,
            'lineItems' => [
                ['description' => 'Ballroom Package Updated', 'itemType' => 'Banquet', 'quantity' => 1, 'unitPrice' => 50000000],
            ],
        ])->assertStatus(200)
            ->assertJsonPath('invoice.total', 50000000);

        // 3. Issue Draft Invoice (SRS-F-16)
        $this->postJson("/api/invoices/{$invId}/issue")
            ->assertStatus(200)
            ->assertJsonPath('invoice.status', 'Issued');

        // 4. Editing Issued Invoice is rejected (SRS-F-15)
        $this->putJson("/api/invoices/{$invId}", [
            'issueDate' => '2026-09-16',
            'dueDate' => '2026-10-16',
            'lineItems' => [],
        ])->assertStatus(422);

        // 5. Cancel Unpaid Issued Invoice succeeds (SRS-F-18)
        $this->postJson("/api/invoices/{$invId}/cancel", [
            'reason' => 'Revisi PO pelanggan',
        ])->assertStatus(200)
            ->assertJsonPath('invoice.status', 'Cancelled')
            ->assertJsonPath('invoice.balanceDue', 0);

        // 6. Cancelling Paid/Partially Paid invoice is rejected (SRS-F-18)
        $paidInv = Invoice::where('invoice_number', 'INV-2026-0002')->firstOrFail();
        $this->postJson("/api/invoices/{$paidInv->id}/cancel", [
            'reason' => 'Test cancel paid invoice',
        ])->assertStatus(422);
    }

    public function test_payment_recording_overpayment_to_dp_audit_and_rollback(): void
    {
        $customer = Customer::where('code', 'CUST-002')->firstOrFail();
        $invoice = Invoice::where('invoice_number', 'INV-2026-0003')->firstOrFail();
        $bank = BankAccount::where('is_default', true)->firstOrFail();

        // Invoice 0003 has balance_due = 60,850,000. Pay 65,000,000 with 4,150,000 overpayment to DP.
        $payRes = $this->postJson('/api/payments', [
            'customerId' => (string) $customer->id,
            'paymentDate' => '2026-09-16',
            'amount' => 65000000,
            'method' => 'bank_transfer',
            'paymentChannel' => 'BCA (Overbooking)',
            'referenceNumber' => 'TRF-TEST-999',
            'bankAccountId' => (string) $bank->id,
            'overpaymentToDeposit' => 4150000,
            'allocations' => [
                ['invoiceId' => (string) $invoice->id, 'allocatedAmount' => 60850000],
            ],
        ]);

        $payRes->assertStatus(201)
            ->assertJsonPath('payment.amount', 65000000)
            ->assertJsonPath('customer.dpBalance', 4150000);

        $this->assertEquals('Paid', $invoice->fresh()->status);
        $this->assertEquals(0, (float) $invoice->fresh()->balance_due);

        $payId = $payRes->json('payment.id');

        // Verify Income Audit FA
        $this->postJson("/api/payments/{$payId}/verify-audit", [
            'auditorName' => 'Rina Hartati (FA)',
            'notes' => 'Klop mutasi BCA',
        ])->assertStatus(200)
            ->assertJsonPath('payment.auditStatus', 'verified_fa');

        // Rollback payment restores invoice balance
        $this->deleteJson("/api/payments/{$payId}")
            ->assertStatus(200);

        $this->assertEquals('Issued', $invoice->fresh()->status);
        $this->assertEquals(60850000, (float) $invoice->fresh()->balance_due);
    }

    public function test_dp_deposit_and_application_to_invoice(): void
    {
        $customer = Customer::where('code', 'CUST-004')->firstOrFail();
        $invoice = Invoice::where('invoice_number', 'INV-2026-0006')->firstOrFail();

        // 1. Deposit DP Rp 20,000,000
        $depRes = $this->postJson('/api/dp-transactions/deposit', [
            'customerId' => (string) $customer->id,
            'amount' => 20000000,
            'method' => 'bank_transfer',
            'paymentChannel' => 'BCA Hotel',
            'referenceNumber' => 'DP-TEST-001',
            'notes' => 'Setoran DP Event',
        ]);

        $depRes->assertStatus(201)
            ->assertJsonPath('customer.dpBalance', 20000000);

        // 2. Apply Rp 13,550,000 DP to INV-2026-0006 (balance 103,550,000 -> 90,000,000)
        $applyRes = $this->postJson('/api/dp-transactions/apply', [
            'customerId' => (string) $customer->id,
            'invoiceId' => (string) $invoice->id,
            'amount' => 13550000,
            'notes' => 'Potong DP ke invoice',
        ]);

        $applyRes->assertStatus(200)
            ->assertJsonPath('customer.dpBalance', 6450000)
            ->assertJsonPath('invoice.balanceDue', 90000000);
    }

    public function test_reports_endpoints_return_expected_structures(): void
    {
        $this->getJson('/api/reports/dashboard-metrics')
            ->assertStatus(200)
            ->assertJsonStructure([
                'totalOutstanding',
                'totalOverdue',
                'collectedThisMonth',
                'openInvoicesCount',
                'overdueInvoicesCount',
                'paidThisMonthCount',
            ]);

        $this->getJson('/api/reports/aging')
            ->assertStatus(200)
            ->assertJsonStructure(['buckets', 'grandTotal']);

        $this->getJson('/api/reports/mutasi-piutang')
            ->assertStatus(200)
            ->assertJsonStructure(['list', 'grandTotal']);

        $sgm = Customer::where('code', 'CUST-008')->firstOrFail();

        $this->getJson("/api/reports/mutasi-piutang/{$sgm->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['customerCode', 'customerName', 'details', 'totalPiutang', 'totalTerbayar', 'saldoAkhir']);

        $this->getJson("/api/reports/kartu-piutang/{$sgm->id}")
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_invoice_penalty_payment_and_waiver_endpoints(): void
    {
        $overdueInv = Invoice::where('invoice_number', '008/INV/SGP-FNC/09-2026')->firstOrFail();

        // 1. Pay Rp 20,000 of the Rp 50,000 penalty
        $payRes = $this->postJson("/api/invoices/{$overdueInv->id}/penalty/pay", [
            'paidAmount' => 20000,
            'paidDate' => '2026-09-16',
            'paymentJournalNumber' => 'BD260916901',
            'paymentNotes' => 'Bayar sebagian denda',
        ]);

        $payRes->assertStatus(200)
            ->assertJsonPath('invoice.penalty.paidAmount', 20000)
            ->assertJsonPath('invoice.penalty.balanceDue', 30000);

        // 2. Waive remaining Rp 30,000 of the penalty
        $waiveRes = $this->postJson("/api/invoices/{$overdueInv->id}/penalty/waive", [
            'waivedAmount' => 30000,
            'waivedDate' => '2026-09-16',
            'waiverMemoNumber' => 'MEMO/FNC/260916/01',
            'waiverNotes' => 'Dispensasi denda manajemen',
        ]);

        $waiveRes->assertStatus(200)
            ->assertJsonPath('invoice.penalty.waivedAmount', 30000)
            ->assertJsonPath('invoice.penalty.balanceDue', 0);
    }
}
