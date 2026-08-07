<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\User;
use App\Services\AdminDashboardMetricsService;
use App\Services\CreditService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\ResellerAnalyticsService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return $this->adminDashboard();
        }

        if ($user->is_reseller) {
            return $this->resellerDashboard($user);
        }

        return $this->customerDashboard($user);
    }

    private function adminDashboard()
    {
        return view('dashboard.admin', app(AdminDashboardMetricsService::class)->metrics());
    }

    private function resellerDashboard($user)
    {
        $analytics = app(ResellerAnalyticsService::class);

        return view('dashboard.reseller', $analytics->dashboardMetrics($user));
    }

    private function customerDashboard($user)
    {
        $usageService = app(ServicePackageUsageService::class);
        $upgradeService = app(CustomerHostingUpgradeService::class);

        $packageUsageWarnings = collect($usageService->upgradeWarningsForUser($user))
            ->map(function (array $warning) use ($upgradeService, $user) {
                $warning['recommended_upgrade'] = $upgradeService->recommendedUpgrade(
                    $warning['service'],
                    $user,
                    $warning['primary_metric'] ?? null,
                );

                return $warning;
            });

        $inFlightServices = $user->services()
            ->whereIn('status', [
                ServiceStatus::Pending->value,
                ServiceStatus::Provisioning->value,
            ])
            ->with(['product', 'invoice'])
            ->get();

        // Only true "provisioning" belongs in the deploy-in-progress banner.
        // Pending + unpaid activation is payment, not provisioning (already covered by invoices).
        $provisioningServices = $inFlightServices
            ->filter(fn ($service) => $service->status === ServiceStatus::Provisioning)
            ->values();

        $pendingSetupServices = $inFlightServices
            ->filter(fn ($service) => $service->status === ServiceStatus::Pending
                && $service->unpaidActivationInvoice() === null)
            ->values();

        return view('dashboard.customer', [
            'activeServices' => $user->services()->where('status', 'active')->with('product')->get(),
            'suspendedServices' => $user->services()->where('status', 'suspended')->with('product')->get(),
            'provisioningServices' => $provisioningServices,
            'pendingSetupServices' => $pendingSetupServices,
            'upcomingDueInvoices' => $user->invoices()
                ->customerFacing()
                ->whereIn('status', ['unpaid', 'overdue'])
                ->orderBy('due_date')
                ->take(5)
                ->get(),
            'outstandingBalance' => $user->getOutstandingBalance(),
            'openTickets' => $user->tickets()->where('status', '!=', 'closed')->get(),
            'domains' => $user->domains()->where('status', 'active')->get(),
            'expiringDomains' => $user->domains()
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(30))
                ->orderBy('expires_at')
                ->get(),
            'creditBalance' => CreditService::getAvailableBalance($user),
            'packageUsageWarnings' => $packageUsageWarnings,
            'nextSteps' => app(\App\Services\Customer\CustomerNextStepsService::class)->forUser($user),
        ]);
    }
}
