<?php

namespace App\Http\Controllers;

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

        return view('dashboard.customer', [
            'activeServices' => $user->services()->where('status', 'active')->with('product')->get(),
            'suspendedServices' => $user->services()->where('status', 'suspended')->with('product')->get(),
            'provisioningServices' => $user->services()->whereIn('status', ['pending', 'provisioning'])->with('product')->get(),
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
