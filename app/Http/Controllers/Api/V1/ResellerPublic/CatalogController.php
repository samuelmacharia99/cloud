<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Services\PublicWebsiteApiContext;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __construct(
        private PublicWebsiteApiContext $api,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'currency' => 'KES',
            'services' => $this->api->listServices(),
            'checkout_url' => $this->api->checkoutUrl(),
        ]);
    }
}
