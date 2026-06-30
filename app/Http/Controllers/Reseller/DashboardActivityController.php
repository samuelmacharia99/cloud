<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerAnalyticsService;
use App\Services\ResellerScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardActivityController extends Controller
{
    public function __invoke(
        Request $request,
        ResellerAnalyticsService $analytics,
        ResellerScopeService $scope,
    ): JsonResponse {
        $reseller = $request->user();
        $offset = max(0, (int) $request->query('offset', 0));
        $limit = min(20, max(1, (int) $request->query('limit', 10)));

        $page = $analytics->paginatedActivityFeed(
            $reseller,
            $scope->managedCustomerIds($reseller),
            $offset,
            $limit,
        );

        return response()->json([
            'items' => $page['items'],
            'has_more' => $page['has_more'],
            'next_offset' => $page['next_offset'],
        ]);
    }
}
