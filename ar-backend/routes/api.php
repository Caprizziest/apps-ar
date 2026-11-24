<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DpTransactionController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

// Bootstrap & Auth / Role Switcher
Route::get('/bootstrap', [BootstrapController::class, 'index']);
Route::get('/auth/me', [BootstrapController::class, 'me']);
Route::post('/auth/switch-role', [BootstrapController::class, 'switchRole']);

// Customers (UC-01, SRS-F-01..03)
Route::get('/customers', [CustomerController::class, 'index']);
Route::get('/customers/{id}', [CustomerController::class, 'show']);
Route::post('/customers', [CustomerController::class, 'store']);
Route::put('/customers/{id}', [CustomerController::class, 'update']);
Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);

// Invoices (UC-02..04, SRS-F-04..19, UC-15)
Route::post('/invoices/detect-overdue', [InvoiceController::class, 'detectOverdue']);
Route::get('/invoices', [InvoiceController::class, 'index']);
Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
Route::post('/invoices', [InvoiceController::class, 'store']);
Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
Route::post('/invoices/{id}/issue', [InvoiceController::class, 'issue']);
Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
Route::post('/invoices/{id}/penalty/pay', [InvoiceController::class, 'payPenalty']);
Route::post('/invoices/{id}/penalty/waive', [InvoiceController::class, 'waivePenalty']);

// Payments & Income Audit (UC-05..06, SRS-F-20..31)
Route::get('/payments', [PaymentController::class, 'index']);
Route::post('/payments', [PaymentController::class, 'store']);
Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
Route::post('/payments/{id}/verify-audit', [PaymentController::class, 'verifyAudit']);

// Down Payments / DP Ledger
Route::get('/dp-transactions', [DpTransactionController::class, 'index']);
Route::post('/dp-transactions/deposit', [DpTransactionController::class, 'deposit']);
Route::post('/dp-transactions/apply', [DpTransactionController::class, 'apply']);

// Bank Accounts (UC-07, SRS-F-32)
Route::get('/bank-accounts', [BankAccountController::class, 'index']);
Route::post('/bank-accounts', [BankAccountController::class, 'store']);
Route::put('/bank-accounts/{id}', [BankAccountController::class, 'update']);
Route::delete('/bank-accounts/{id}', [BankAccountController::class, 'destroy']);

// Reports (Dashboard Metrics, Aging, Mutasi Piutang, Kartu Piutang)
Route::get('/reports/dashboard-metrics', [ReportController::class, 'dashboardMetrics']);
Route::get('/reports/aging', [ReportController::class, 'aging']);
Route::get('/reports/mutasi-piutang', [ReportController::class, 'mutasiSummary']);
Route::get('/reports/mutasi-piutang/{customerId}', [ReportController::class, 'mutasiDetail']);
Route::get('/reports/kartu-piutang/{customerId}', [ReportController::class, 'kartuPiutang']);

// Activity Logs
Route::get('/activity-logs', [ActivityLogController::class, 'index']);
