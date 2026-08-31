<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeployProjectWorkloadRequest;
use App\Http\Requests\DestroyCustomerProjectRequest;
use App\Http\Requests\MoveCustomerServiceProjectRequest;
use App\Http\Requests\RenameCustomerProjectRequest;
use App\Http\Requests\RenameCustomerServiceRequest;
use App\Http\Requests\StoreCustomerProjectRequest;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\DatabaseTemplate;
use App\Models\Service;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Customer\CustomerProjectRemovalService;
use App\Services\Customer\CustomerProjectService;
use App\Services\Customer\CustomerServiceCancellationService;
use App\Services\Customer\CustomerServiceRenewalService;
use App\Services\Customer\ProjectWorkloadDeployService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\Provisioning\WordPressAdminLoginService;
use App\Services\ServiceEnforcementInsightService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(CustomerProjectService $projectService)
    {
        $user = auth()->user();
        $projectService->ensureForUser($user);

        $services = $user->services()
            ->with(['product.containerTemplate', 'resellerProduct', 'invoice', 'project', 'containerDeployment'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->whereHas('product', function ($q) {
                $q->where('type', '!=', 'domain');
            })
            ->latest()
            ->get();

        $projects = $user->customerProjects()
            ->with(['billingService.product.containerTemplate', 'billingService.containerDeployment'])
            ->orderBy('name')
            ->get();
        $serviceGroups = $projectService->groupForDisplay($services, $projects);

        return view('customer.services.index', compact(
            'services',
            'serviceGroups',
            'projects',
        ));
    }

    public function rename(RenameCustomerServiceRequest $request, Service $service)
    {
        $this->authorize('rename', $service);

        $service->update([
            'name' => $request->validated('name'),
        ]);

        return back()->with('success', 'Service renamed successfully.');
    }

    public function storeProject(StoreCustomerProjectRequest $request, CustomerProjectService $projectService)
    {
        $this->authorize('create', CustomerProject::class);

        $user = $request->user();
        $service = null;

        if ($request->filled('service_id')) {
            $service = Service::query()->findOrFail($request->validated('service_id'));
            $this->authorize('rename', $service);
            if ((int) $service->user_id !== (int) $user->id) {
                abort(403);
            }
        }

        $project = $projectService->createProject(
            $user,
            $request->validated('name'),
            $service,
        );

        return redirect()
            ->route('customer.projects.show', $project)
            ->with('success', 'Project “'.$project->name.'” created.');
    }

    public function showProject(CustomerProject $project, CustomerProjectService $projectService)
    {
        $this->authorize('view', $project);

        if ((int) $project->user_id !== (int) auth()->id()) {
            abort(403);
        }

        $user = auth()->user();
        $project->load(['billingService.product.containerTemplate', 'billingService.containerDeployment']);

        $services = $user->services()
            ->with(['product.containerTemplate', 'resellerProduct', 'invoice', 'containerDeployment'])
            ->where('project_id', $project->id)
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->whereHas('product', fn ($q) => $q->where('type', '!=', 'domain'))
            ->latest()
            ->get();

        $projects = $user->customerProjects()->orderBy('name')->get();
        $containers = $projectService->containerLabelsForMembers($services);
        $primaryContainer = $services->first(fn (Service $s) => $s->isContainerHosting());

        return view('customer.services.project-show', compact(
            'project',
            'services',
            'projects',
            'containers',
            'primaryContainer',
        ));
    }

    public function renameProject(RenameCustomerProjectRequest $request, CustomerProject $project)
    {
        $this->authorize('rename', $project);

        $project->update([
            'name' => $request->validated('name'),
        ]);

        return back()->with('success', 'Project renamed.');
    }

    public function destroyProject(DestroyCustomerProjectRequest $request, CustomerProject $project, CustomerProjectRemovalService $removal)
    {
        $this->authorize('delete', $project);

        try {
            $result = $removal->remove($project, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['confirm_name' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['confirm_name' => $e->getMessage()]);
        }

        return redirect()->route('customer.services.index')->with('success', $result['message']);
    }

    public function moveService(MoveCustomerServiceProjectRequest $request, Service $service, CustomerProjectService $projectService)
    {
        $this->authorize('rename', $service);

        $projectId = $request->validated('project_id');
        $project = $projectId
            ? CustomerProject::query()->where('user_id', $request->user()->id)->findOrFail($projectId)
            : null;

        $projectService->assignService($service, $project);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'project_id' => $project?->id,
                'message' => $project
                    ? 'Moved to “'.$project->name.'”.'
                    : 'Removed from project.',
            ]);
        }

        return back()->with('success', $project
            ? 'Moved to “'.$project->name.'”.'
            : 'Removed from project.');
    }

    public function deployForm(CustomerProject $project)
    {
        $this->authorize('update', $project);

        $languages = ContainerTemplate::active()
            ->reorder()
            ->orderByRaw("CASE slug
                WHEN 'wordpress' THEN 1
                WHEN 'nodejs' THEN 2
                WHEN 'python' THEN 3
                WHEN 'static-site' THEN 4
                ELSE 100
            END")
            ->orderBy('order')
            ->orderBy('name')
            ->get();
        $databases = DatabaseTemplate::active()->get();
        $includedDeploy = $project->canDeployIncludedWorkload();

        return view('customer.select-techstack', [
            'languages' => $languages,
            'databases' => $databases,
            'cartCount' => 0,
            'attachDomain' => null,
            'project' => $project,
            'includedDeploy' => $includedDeploy,
            'stackFormAction' => $includedDeploy
                ? route('customer.projects.deploy.store', $project)
                : route('customer.confirm-techstack.store'),
        ]);
    }

    public function deploy(
        DeployProjectWorkloadRequest $request,
        CustomerProject $project,
        ProjectWorkloadDeployService $deployer,
    ) {
        $this->authorize('deployWorkload', $project);

        $language = ContainerTemplate::query()->findOrFail($request->validated('language_id'));
        $database = $request->filled('database_id')
            ? DatabaseTemplate::query()->findOrFail($request->validated('database_id'))
            : null;

        try {
            $service = $deployer->deploy(
                $request->user(),
                $project,
                $language,
                $database,
                $request->validated('framework'),
                $request->validated('frontend'),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return back()->withErrors(['language_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('customer.services.show', $service)
            ->with('success', $service->name.' was added to '.$project->name.' on the existing plan. Extra usage above the plan is billed as overage.');
    }

    public function wordpressAdminLogin(Service $service, WordPressAdminLoginService $loginService)
    {
        $this->authorize('wordpressAdminLogin', $service);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        try {
            $url = $loginService->createLoginUrl($service);

            return redirect()->away($url);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'error' => $e->getMessage() ?: 'Could not open WordPress admin. Try again in a moment.',
            ]);
        }
    }

    public function show(Service $service)
    {
        $this->authorize('view', $service);

        if ($invoice = $service->unpaidActivationInvoice()) {
            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('info', 'Complete payment to activate this service.');
        }

        // Redirect container services to their dedicated dashboard
        if ($service->product?->type === 'container_hosting') {
            return redirect()->route('customer.services.container.show', $service);
        }

        if ($service->isEmailHosting()) {
            return redirect()->route('customer.services.email.show', $service);
        }

        $service->load(['product.directAdminPackage', 'invoice', 'node']);

        $packageUsageInsight = null;
        $recommendedUpgrade = null;

        if ($service->isSharedHosting()) {
            $usageService = app(ServicePackageUsageService::class);

            if ($usageService->snapshotFromMeta($service) === null) {
                $liveUsage = $usageService->fetchLiveUsage($service);
                if ($liveUsage !== null) {
                    $usageService->persistSnapshot($service, $liveUsage, $usageService->lastDashboard());
                    $service->refresh();
                }
            }

            $packageUsageInsight = app(ServiceEnforcementInsightService::class)->forService($service);
            $recommendedUpgrade = app(CustomerHostingUpgradeService::class)->recommendedUpgrade(
                $service,
                auth()->user(),
                $packageUsageInsight['primary_metric'] ?? null,
            );
        }

        return view('customer.services.show', compact('service', 'packageUsageInsight', 'recommendedUpgrade'));
    }

    public function cancel(Request $request, Service $service, CustomerServiceCancellationService $cancellation)
    {
        $this->authorize('view', $service);

        $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        try {
            $result = $cancellation->cancel($service, auth()->user(), $request->reason);

            return redirect()->route('customer.services.index')
                ->with($result['deprovisioned'] ? 'success' : 'warning', $result['message']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function renewForm(Service $service, CustomerServiceRenewalService $renewals)
    {
        $this->authorize('view', $service);
        abort_if(
            ! in_array($service->status->value, ['active', 'suspended']),
            422,
            'Only active or suspended services can be renewed.'
        );

        $service->load('product.directAdminPackage');

        if ($existingInvoice = $renewals->findOutstandingRenewalInvoice($service)) {
            return redirect()->route('customer.payment.select-method', $existingInvoice)
                ->with('info', 'You already have an outstanding renewal invoice. Complete the payment below to extend your service.');
        }

        $renewalOptions = $renewals->renewalOptions($service, auth()->user());

        return view('customer.services.renew', [
            'service' => $service,
            'renewalOptions' => $renewalOptions,
            'renewals' => $renewals,
        ]);
    }

    public function renew(Request $request, Service $service, CustomerServiceRenewalService $renewals)
    {
        $this->authorize('view', $service);
        abort_if(
            ! in_array($service->status->value, ['active', 'suspended']),
            422,
            'Only active or suspended services can be renewed.'
        );

        $service->load('product');

        if ($existingInvoice = $renewals->findOutstandingRenewalInvoice($service)) {
            return redirect()->route('customer.payment.select-method', $existingInvoice)
                ->with('info', 'You already have an outstanding renewal invoice. Complete the payment below to extend your service.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
        ]);

        $resellerProductId = isset($validated['reseller_product_id'])
            ? (int) $validated['reseller_product_id']
            : null;

        try {
            $invoice = $renewals->createRenewalInvoice(
                $service,
                auth()->user(),
                (int) $validated['product_id'],
                $resellerProductId,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('success', 'Renewal invoice created. Choose a payment method below to extend your service.');
    }
}
