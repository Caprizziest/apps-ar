<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ArTransformer;
use Illuminate\Http\JsonResponse;

class ActivityLogController extends Controller
{
    public function index(): JsonResponse
    {
        $logs = ActivityLog::orderByDesc('logged_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ActivityLog $l) => ArTransformer::activityLog($l));

        return response()->json($logs);
    }
}
