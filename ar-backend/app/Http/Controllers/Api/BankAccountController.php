<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Payment;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = BankAccount::orderBy('id')
            ->get()
            ->map(fn (BankAccount $b) => ArTransformer::bankAccount($b));

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $bankName = trim((string) $request->input('bankName', ''));
        $accountNumber = trim((string) $request->input('accountNumber', ''));
        $accountHolder = trim((string) $request->input('accountHolder', ''));
        $isDefault = (bool) $request->input('isDefault', false);

        $duplicate = BankAccount::where('bank_name', $bankName)
            ->where('account_number', $accountNumber)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor rekening pada bank yang sama telah terdaftar.',
            ], 422);
        }

        if ($isDefault) {
            BankAccount::where('is_default', true)->update(['is_default' => false]);
        }

        $account = BankAccount::create([
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'account_holder' => $accountHolder,
            'is_default' => $isDefault,
            'is_active' => true,
        ]);

        $log = ArService::logActivity([
            'type' => 'bank_account_created',
            'description' => "Rekening bank baru {$account->bank_name} ({$account->account_number}) ditambahkan",
            'reference_id' => $account->account_number,
            'badge_type' => 'info',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Rekening {$account->bank_name} berhasil ditambahkan!",
            'bankAccount' => ArTransformer::bankAccount($account),
            'activityLog' => ArTransformer::activityLog($log),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = BankAccount::find($id);
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Rekening bank tidak ditemukan.'], 404);
        }

        if ($request->boolean('isDefault')) {
            BankAccount::where('id', '!=', $account->id)->update(['is_default' => false]);
        }

        $account->update([
            'bank_name' => $request->input('bankName', $account->bank_name),
            'account_number' => $request->input('accountNumber', $account->account_number),
            'account_holder' => $request->input('accountHolder', $account->account_holder),
            'is_default' => $request->has('isDefault') ? (bool) $request->input('isDefault') : $account->is_default,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Rekening {$account->bank_name} berhasil diperbarui.",
            'bankAccount' => ArTransformer::bankAccount($account->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = BankAccount::find($id);
        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Rekening bank tidak ditemukan.'], 404);
        }

        // SRS-F-32 rule: "Rekening bank yang telah digunakan pada pembayaran tidak dapat dihapus"
        $usedInPayment = Payment::withTrashed()->where('bank_account_id', $account->id)->exists();
        if ($usedInPayment) {
            return response()->json([
                'success' => false,
                'message' => "Rekening {$account->bank_name} telah digunakan pada pencatatan pembayaran dan tidak dapat dihapus.",
            ], 422);
        }

        $bankName = $account->bank_name;
        $accNum = $account->account_number;
        $account->delete();

        $log = ArService::logActivity([
            'type' => 'bank_account_deleted',
            'description' => "Rekening bank {$bankName} ({$accNum}) dihapus",
            'reference_id' => $accNum,
            'badge_type' => 'danger',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Rekening {$bankName} berhasil dihapus.",
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }
}
