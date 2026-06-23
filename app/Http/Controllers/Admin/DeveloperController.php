<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PlatformApiTokenService;
use App\Services\PlatformPublicApiService;
use App\Services\ResellerBrandingResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class DeveloperController extends Controller
{
    public function __construct(
        private PlatformApiTokenService $apiToken,
        private PlatformPublicApiService $publicApi,
        private ResellerBrandingResolver $brandingResolver,
    ) {}

    public function index(): View
    {
        $platformHosts = $this->brandingResolver->platformHosts();
        $requestHost = $this->brandingResolver->normalizeHost(request()->getHost());
        $portalUrl = $this->brandingResolver->platformBaseUrl();

        return view('admin.developers.index', [
            'apiEnabled' => $this->publicApi->isEnabled(),
            'publicApiSettings' => $this->publicApi->settings(),
            'apiBaseUrl' => $this->publicApi->apiBaseUrl(),
            'checkoutUrl' => $this->publicApi->checkoutUrl(),
            'portalUrl' => $portalUrl,
            'portalUrlDiffers' => strtolower($portalUrl) !== strtolower($this->brandingResolver->publicApiBaseUrl()),
            'platformHosts' => $platformHosts,
            'requestHost' => $requestHost,
            'hostRecognized' => $this->brandingResolver->isPlatformHost($requestHost),
            'tokenMetadata' => $this->apiToken->metadata(),
            'plainTextToken' => session()->pull('platform_api_plain_token'),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'public_api_enabled' => 'nullable|boolean',
            'public_api_allowed_origins' => 'nullable|string|max:2000',
        ]);

        $origins = $validated['public_api_allowed_origins'] ?? '';
        $parsedOrigins = preg_split('/[\s,]+/', (string) $origins, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $this->publicApi->updateSettings(
            filter_var($validated['public_api_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            $parsedOrigins,
        );

        return redirect()
            ->route('admin.developers.index')
            ->with('success', 'Website API settings saved.');
    }

    public function regenerateToken(Request $request): RedirectResponse
    {
        $admin = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, $admin->password)) {
            return back()->withErrors(['password' => 'The password you entered is incorrect.']);
        }

        if (! $this->publicApi->isEnabled()) {
            return back()->with('error', 'Enable the public website API on this page before generating a token.');
        }

        $plainText = $this->apiToken->regenerate($admin);

        return redirect()
            ->route('admin.developers.index')
            ->with('success', 'API token regenerated.')
            ->with('platform_api_plain_token', $plainText);
    }

    public function revealToken(Request $request): RedirectResponse
    {
        $admin = auth()->user();

        $request->validate([
            'password' => 'required|string',
        ]);

        if (! Hash::check($request->password, $admin->password)) {
            return back()->withErrors(['reveal_password' => 'The password you entered is incorrect.']);
        }

        if (! $this->apiToken->hasActiveToken()) {
            return back()->with('error', 'No API token exists yet. Generate one first.');
        }

        $plainText = $this->apiToken->revealPlainText();

        if ($plainText === null) {
            return back()->with('error', 'This token cannot be copied. Regenerate it once to enable copying later.');
        }

        return redirect()
            ->route('admin.developers.index')
            ->with('platform_api_plain_token', $plainText);
    }
}
