<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerApiTokenService;
use App\Services\ResellerBrandingResolver;
use App\Services\ResellerPublicApiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class DeveloperController extends Controller
{
    public function __construct(
        private ResellerApiTokenService $apiToken,
        private ResellerPublicApiService $publicApi,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function index(): View
    {
        $reseller = auth()->user();
        $branding = $this->brandingResolver->forReseller($reseller);
        $customDomain = $branding['custom_domain'] ?? null;
        $apiEnabled = $this->publicApi->isEnabled($reseller);
        $apiBaseUrl = $customDomain ? $this->publicApi->portalBaseUrl($reseller).'/api/v1/public' : null;

        return view('reseller.developers.index', [
            'apiEnabled' => $apiEnabled,
            'publicApiSettings' => $this->publicApi->settings($reseller),
            'customDomain' => $customDomain,
            'apiBaseUrl' => $apiBaseUrl,
            'checkoutUrl' => $customDomain ? $this->publicApi->checkoutUrl($reseller) : null,
            'tokenMetadata' => $this->apiToken->metadata($reseller),
            'plainTextToken' => session()->pull('reseller_api_plain_token'),
        ]);
    }

    public function regenerateToken(Request $request): RedirectResponse
    {
        $reseller = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, $reseller->password)) {
            return back()->withErrors(['password' => 'The password you entered is incorrect.']);
        }

        if (! $this->publicApi->isEnabled($reseller)) {
            return back()->with('error', 'Enable the public website API in Settings → Branding before generating a token.');
        }

        $plainText = $this->apiToken->regenerate($reseller);

        return redirect()
            ->route('reseller.developers.index')
            ->with('success', 'API token regenerated.')
            ->with('reseller_api_plain_token', $plainText);
    }

    public function revealToken(Request $request): RedirectResponse
    {
        $reseller = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, $reseller->password)) {
            return back()->withErrors(['reveal_password' => 'The password you entered is incorrect.']);
        }

        if (! $this->apiToken->hasActiveToken($reseller)) {
            return back()->with('error', 'No API token exists yet. Generate one first.');
        }

        $plainText = $this->apiToken->revealPlainText($reseller);

        if ($plainText === null) {
            return back()->with('error', 'This token cannot be copied. Regenerate it once to enable copying later.');
        }

        return redirect()
            ->route('reseller.developers.index')
            ->with('reseller_api_plain_token', $plainText);
    }
}
