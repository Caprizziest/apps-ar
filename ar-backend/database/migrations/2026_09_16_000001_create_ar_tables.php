<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Bank Accounts Master Data (SRS-F-32, UC-07)
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_holder');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bank_name', 'account_number']);
        });

        // 2. Customers Master Data (SRS-F-01..03, UC-01)
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('nik', 20)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->unsignedInteger('due_days')->default(30);
            $table->string('status', 30)->default('active'); // active, credit_hold
            $table->decimal('dp_balance', 15, 2)->default(0);
            $table->string('company_unit')->nullable()->default('PT. GALESONG PRATAMA HOTEL');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 3. Invoices Header (SRS-F-04..19, UC-02..04)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 100)->unique();
            $table->string('journal_number', 100)->nullable()->index();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('customer_nik', 20)->nullable();
            $table->string('customer_npwp', 30)->nullable();
            $table->date('issue_date')->index();
            $table->date('due_date')->index();
            $table->string('status', 30)->default('Draft')->index(); // Draft, Issued, Partially Paid, Paid, Overdue, Cancelled
            $table->string('invoice_type')->default('Standard Hospitality Invoice');
            $table->string('source_type', 30)->default('generic'); // generic, city_ledger, direct
            $table->string('company_unit')->nullable()->default('PT. GALESONG PRATAMA HOTEL');

            // Financial Totals
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('pph_rate', 5, 2)->default(0);
            $table->decimal('pph_amount', 15, 2)->default(0);
            $table->decimal('dp_deduction', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->default(0);
            $table->text('notes')->nullable();

            // Installments (Cicilan) & Rollover
            $table->boolean('is_installment')->default(false);
            $table->unsignedInteger('installment_count')->nullable();
            $table->unsignedInteger('installment_interval_days')->nullable();
            $table->unsignedInteger('times_overdue')->default(0);
            $table->boolean('has_rollover')->default(false);
            $table->decimal('rolled_over_amount', 15, 2)->default(0);
            $table->string('rolled_over_from', 100)->nullable();
            $table->foreignId('rolled_over_from_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            // Audit & Cancellation
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_name')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // 4. Invoice Line Items / Details
        Schema::create('invoice_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');
            $table->string('item_type', 50)->default('Service'); // Room, F&B, Banquet, Service, Facility, Other
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->timestamps();
        });

        // 5. Invoice Installment Schedule (Cicilan)
        Schema::create('invoice_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->string('status', 30)->default('Unpaid'); // Unpaid, Paid, Overdue
            $table->date('paid_date')->nullable();
            $table->timestamps();
        });

        // 6. Invoice Penalties (Kartu Piutang Denda & Penghapusan Denda)
        Schema::create('invoice_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->decimal('penalty_amount', 15, 2)->default(0);

            // Pembayaran Denda
            $table->date('paid_date')->nullable();
            $table->string('payment_journal_number', 100)->nullable();
            $table->string('payment_notes')->nullable();
            $table->decimal('paid_amount', 15, 2)->default(0);

            // Penghapusan Denda (Waiver)
            $table->date('waived_date')->nullable();
            $table->string('waiver_memo_number', 100)->nullable();
            $table->string('waiver_notes')->nullable();
            $table->decimal('waived_amount', 15, 2)->default(0);
            $table->foreignId('waived_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('balance_due', 15, 2)->default(0);
            $table->timestamps();
        });

        // 7. Payments (SRS-F-20..31, UC-05..06)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 100)->unique();
            $table->string('journal_number', 100)->nullable()->index();
            $table->string('kwitansi_number', 100)->nullable();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->date('payment_date')->index();
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('admin_fee', 15, 2)->default(0);
            $table->string('method', 50)->default('bank_transfer'); // bank_transfer, virtual_account, e_wallet, qris, credit_card, cash, cheque
            $table->string('payment_channel', 100)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->boolean('is_down_payment')->default(false);
            $table->decimal('overpayment_to_deposit', 15, 2)->default(0);
            $table->text('notes')->nullable();

            // Income Audit (Verifikasi Fisik Dept FA)
            $table->string('audit_status', 30)->default('pending_fa')->index(); // pending_fa, verified_fa
            $table->foreignId('audited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audited_by_name')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->text('audit_notes')->nullable();

            // Attachment (Bukti Fisik Slip Setoran)
            $table->string('attachment_name')->nullable();
            $table->string('attachment_size', 50)->nullable();
            $table->text('attachment_url')->nullable();

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // 8. Payment Allocations (Pivot between Payment & Invoice)
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->unsignedInteger('times_paid')->default(1);
            $table->timestamps();
        });

        // 9. Customer DP Transactions (Buku Pembantu Uang Muka / DP Ledger)
        Schema::create('customer_dp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('transaction_date')->index();
            $table->string('type', 30); // deposit, applied
            $table->decimal('amount', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 2)->default(0);
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 10. Activity Logs / Audit Trail (UC-11, UC-16, SRS-F-39..40)
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_role', 30)->nullable();
            $table->string('type', 50)->index();
            $table->text('description');
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('reference_id', 100)->nullable()->index();
            $table->string('badge_type', 30)->default('info'); // info, success, warning, danger, secondary
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('customer_dp_transactions');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_penalties');
        Schema::dropIfExists('invoice_installments');
        Schema::dropIfExists('invoice_details');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('bank_accounts');
    }
};
