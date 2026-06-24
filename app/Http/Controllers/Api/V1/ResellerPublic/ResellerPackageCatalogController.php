<?php

namespace App\Http\Controllers\Api\V1\ResellerPublic;

use App\Http\Controllers\Controller;
use App\Services\PublicWebsiteApiContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResellerPackageCatalogController extends Controller
{
    public function __construct(
        private PublicWebsiteApiContext $api,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->api->isPlatform()) {
            abort(404);
        }

        $cycle = $request->query('cycle');
        if ($cycle !== null && $cycle !== '' && ! in_array($cycle, ['monthly', 'annually'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid billing cycle. Use monthly or annually.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'currency' => 'KES',
            'checkout_url' => $this->api->checkoutUrl(),
            'packages' => $this->api->listResellerPackages($cycle),
        ]);
    }
}
