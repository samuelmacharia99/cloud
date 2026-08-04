<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerDirectAdminMonitorService;
use App\Services\ResellerDirectAdminService;
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

    /**
     * One-time DirectAdmin login for the authenticated reseller account.
     */
    public function panelLogin(ResellerDirectAdminService $directAdmin)
    {
        $result = $directAdmin->createPanelLoginUrl(auth()->user());

        if (! ($result['success'] ?? false) || empty($result['url'])) {
            return redirect()
                ->route('dashboard')
                ->with('error', $result['message'] ?? 'Unable to open DirectAdmin.');
        }

        return redirect()->away($result['url']);
    }
}
