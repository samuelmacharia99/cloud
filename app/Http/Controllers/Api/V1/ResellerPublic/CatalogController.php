<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Services\ResellerPublicApiService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private ResellerPublicApiService $publicApi,
    ) {}

    public function index(): JsonResponse
    {
        $reseller = app('currentReseller');

        return response()->json([
            'success' => true,
            'currency' => 'KES',
            'services' => $this->publicApi->listServices($reseller),
            'checkout_url' => $this->publicApi->checkoutUrl($reseller),
        ]);
    }
}
