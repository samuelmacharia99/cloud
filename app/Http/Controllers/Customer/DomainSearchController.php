<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Services\DomainAvailabilityService;
use App\Services\ResellerCustomerCatalogService;
use Illuminate\Http\Request;

class DomainSearchController extends Controller
{
    public function __construct(
        private DomainAvailabilityService $availability,
    ) {}

    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (empty($query)) {
            return response()->json([
                'success' => false,
                'message' => 'Please enter a domain name to search',
                'results' => [],
            ]);
        }

        $query = str_replace(['www.', 'https://', 'http://'], '', strtolower(trim($query)));

        $catalogService = app(ResellerCustomerCatalogService::class);
        $user = auth()->user();
        $allowedExtensions = DomainExtension::where('enabled', true)->pluck('extension')->all();
        $results = [];

        if (str_contains($query, '.')) {
            $check = $this->availability->checkInput($query, null, $allowedExtensions);

            if ($check) {
                $extension = DomainExtension::where('extension', $check['extension'])->first();

                if ($extension) {
                    $price = $catalogService->domainRegistrationPrice($user, $extension, 1) ?? 0;

                    $results[] = [
                        'domain' => $check['name'],
                        'extension' => $check['extension'],
                        'full_domain' => $check['full_domain'],
                        'available' => $check['available'],
                        'price' => $price,
                        'currency' => 'KES',
                        'years' => [1, 2, 3, 5],
                    ];
                }
            }
        } else {
            foreach (DomainExtension::where('enabled', true)->get() as $ext) {
                $check = $this->availability->checkInput($query, $ext->extension, $allowedExtensions);

                if ($check === null) {
                    continue;
                }

                $price = $catalogService->domainRegistrationPrice($user, $ext, 1);

                if ($price === null) {
                    continue;
                }

                $results[] = [
                    'domain' => $check['name'],
                    'extension' => $check['extension'],
                    'full_domain' => $check['full_domain'],
                    'available' => $check['available'],
                    'price' => $price,
                    'currency' => 'KES',
                    'years' => [1, 2, 3, 5],
                ];
            }
        }

        usort($results, fn ($a, $b) => $b['available'] <=> $a['available']);

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }
}
