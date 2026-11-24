<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\CustomerDpTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = ArService::resolveActiveUser($request);

        $bankAccounts = BankAccount::orderBy('id')->get()->map(fn ($b) => ArTransformer::bankAccount($b));
        $customers = Customer::orderBy('id')->get()->map(fn ($c) => ArTransformer::customer($c));
        $invoices = Invoice::with(['customer', 'details', 'installments', 'createdBy'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($i) => ArTransformer::invoice($i));
        $payments = Payment::with(['customer', 'allocations.invoice'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($p) => ArTransformer::payment($p));
        $dpTransactions = CustomerDpTransaction::with(['customer', 'invoice', 'payment'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => ArTransformer::dpTransaction($t));
        $activityLogs = ActivityLog::orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($l) => ArTransformer::activityLog($l));

        return response()->json([
            'today' => ArService::TODAY,
            'currentUser' => ArTransformer::user($user),
            'bankAccounts' => $bankAccounts,
            'customers' => $customers,
            'invoices' => $invoices,
            'payments' => $payments,
            'dpTransactions' => $dpTransactions,
            'activityLogs' => $activityLogs,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = ArService::resolveActiveUser($request);
        return response()->json(ArTransformer::user($user));
    }

    public function switchRole(Request $request): JsonResponse
    {
        $role = $request->input('role', 'Admin');
        $user = User::where('role', $role)->first() ?? ArService::resolveActiveUser($request);

        $log = ArService::logActivity([
            'user_name' => $user->full_name,
            'user_role' => $role,
            'type' => 'status_changed',
            'description' => "Active role switched to {$role}",
            'badge_type' => 'info',
        ], $request);

        return response()->json([
            'user' => ArTransformer::user($user),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }
}
