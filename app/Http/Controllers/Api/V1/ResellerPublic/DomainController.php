<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Services\ResellerPublicApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(
        private ResellerPublicApiService $publicApi,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|max:253',
            'period' => 'nullable|integer|min:1|max:10',
        ]);

        $reseller = app('currentReseller');
        $query = $validated['q'];

        if (trim($query) === '') {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a domain name to search.',
                'results' => [],
            ], 422);
        }

        $payload = $this->publicApi->searchDomains(
            $reseller,
            $query,
            (int) ($validated['period'] ?? 1),
        );

        return response()->json($payload);
    }

    public function extensions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|integer|min:1|max:10',
        ]);

        $reseller = app('currentReseller');
        $period = (int) ($validated['period'] ?? 1);

        return response()->json([
            'success' => true,
            'period_years' => $period,
            'currency' => 'KES',
            'extensions' => $this->publicApi->listExtensions($reseller, $period),
            'checkout_url' => $this->publicApi->checkoutUrl($reseller),
        ]);
    }
}
