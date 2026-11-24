<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\ArService;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = Customer::orderByDesc('id')
            ->get()
            ->map(fn (Customer $c) => ArTransformer::customer($c));

        return response()->json($customers);
    }

    public function show(string $id): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
        }

        return response()->json(ArTransformer::customer($customer));
    }

    public function store(Request $request): JsonResponse
    {
        $cleanNik = $request->input('nik') ? preg_replace('/\D/', '', (string) $request->input('nik')) : null;

        $customer = Customer::create([
            'code' => ArService::nextCustomerCode(),
            'name' => $request->input('name'),
            'contact_person' => $request->input('contactPerson'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'nik' => $cleanNik ?: null,
            'npwp' => $request->input('npwp') ?: null,
            'credit_limit' => (float) $request->input('creditLimit', 0),
            'due_days' => (int) $request->input('dueDays', 30),
            'status' => $request->input('status', 'active'),
            'dp_balance' => (float) $request->input('dpBalance', 0),
            'notes' => $request->input('notes', ''),
        ]);

        $log = ArService::logActivity([
            'type' => 'customer_created',
            'description' => "Customer baru \"{$customer->name}\" ({$customer->code}) didaftarkan",
            'reference_id' => $customer->code,
            'badge_type' => 'info',
        ], $request);

        return response()->json([
            'success' => true,
            'customer' => ArTransformer::customer($customer),
            'activityLog' => ArTransformer::activityLog($log),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
        }

        $updates = [];
        if ($request->has('name')) $updates['name'] = $request->input('name');
        if ($request->has('contactPerson')) $updates['contact_person'] = $request->input('contactPerson');
        if ($request->has('email')) $updates['email'] = $request->input('email');
        if ($request->has('phone')) $updates['phone'] = $request->input('phone');
        if ($request->has('address')) $updates['address'] = $request->input('address');
        if ($request->has('nik')) {
            $cleanNik = $request->input('nik') ? preg_replace('/\D/', '', (string) $request->input('nik')) : null;
            $updates['nik'] = $cleanNik ?: null;
        }
        if ($request->has('npwp')) $updates['npwp'] = $request->input('npwp') ?: null;
        if ($request->has('creditLimit')) $updates['credit_limit'] = (float) $request->input('creditLimit');
        if ($request->has('dueDays')) $updates['due_days'] = (int) $request->input('dueDays');
        if ($request->has('status')) $updates['status'] = $request->input('status');
        if ($request->has('notes')) $updates['notes'] = $request->input('notes');
        if ($request->has('dpBalance')) $updates['dp_balance'] = (float) $request->input('dpBalance');

        $customer->update($updates);

        $log = ArService::logActivity([
            'type' => 'customer_updated',
            'description' => "Data profil customer \"{$customer->name}\" ({$customer->code}) diperbarui",
            'reference_id' => $customer->code,
            'badge_type' => 'info',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Data customer {$customer->name} berhasil diperbarui!",
            'customer' => ArTransformer::customer($customer->fresh()),
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
        }

        // SRS-F-03 Rule: "mencegah penghapusan pelanggan yang telah memiliki invoice"
        $invCount = $customer->invoices()->count();
        if ($invCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Pelanggan \"{$customer->name}\" tidak dapat dihapus karena telah memiliki {$invCount} invoice terdaftar.",
            ], 422);
        }

        $name = $customer->name;
        $code = $customer->code;
        $customer->delete();

        $log = ArService::logActivity([
            'type' => 'customer_deleted',
            'description' => "Customer \"{$name}\" ({$code}) dihapus dari sistem",
            'reference_id' => $code,
            'badge_type' => 'danger',
        ], $request);

        return response()->json([
            'success' => true,
            'message' => "Customer {$name} berhasil dihapus.",
            'activityLog' => ArTransformer::activityLog($log),
        ]);
    }
}
