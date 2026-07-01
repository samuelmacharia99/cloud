<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerDirectAdminMonitorService;
use Illuminate\Http\JsonResponse;

class DashboardDirectAdminController extends Controller
{
    public function live(ResellerDirectAdminMonitorService $monitor): JsonResponse
    {
        return response()->json($monitor->liveSnapshot(auth()->user()));
    }

    public function panel(ResellerDirectAdminMonitorService $monitor): JsonResponse
    {
        return response()->json($monitor->panelData(auth()->user()));
    }
}
