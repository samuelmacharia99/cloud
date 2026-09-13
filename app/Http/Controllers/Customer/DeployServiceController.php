<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ContainerTemplate;
use App\Models\CustomerProject;
use App\Models\DatabaseTemplate;
use App\Services\Checkout\CartLineException;
use App\Services\Checkout\CartLineFactory;
use App\Services\Checkout\SharedHostingCheckoutService;
use App\Services\Customer\DeployTargetService;
use App\Services\Customer\StackEligibilityService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TechStackRoutingService;
use App\Services\UserCurrencyService;
use App\Support\SessionCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The deploy page, plan first.
 *
 * A customer first says where the new service runs: on an existing plan
 * with room (deploys at once, nothing to pay) or on a new plan (chosen
 * here, paid at checkout). Only then do they pick a stack, and the picker
 * shows only what that plan can run. The old stack-first pages still work
 * for direct links but are no longer the way in.
 */
class DeployServiceController extends Controller
{
    public const SESSION_PLAN = 'selected_plan';

    public function __construct(
        private DeployTargetService $targets,
        private StackEligibilityService $eligibility,
        private ResellerCustomerCatalogService $catalog,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        session()->forget([self::SESSION_PLAN, 'selected_techstack']);

        $project = $request->integer('project') > 0
            ? CustomerProject::query()->where('user_id', $user->id)->whereKey($request->integer('project'))->first()
            : null;

        $currency = app(UserCurrencyService::class)->model($user);

        return view('customer.deploy.hub', [
            'existingPlans' => $this->targets->existingPlans($user),
            'newPlans' => $this->targets->newPlans($user),
            'project' => $project,
            'isResellerCustomer' => $this->catalog->isResellerCustomer($user),
            'currency' => $currency,
            'currencyCode' => $currency->code,
            'cartCount' => count(SessionCart::portal()),
            'attachDomain' => app(SharedHostingCheckoutService::class)->attachDomainFromSession(),
        ]);
    }

    public function choosePlan(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'reseller_product_id' => ['nullable', 'integer'],
            'billing_cycle' => ['required', 'in:'.implode(',', CartLineFactory::BILLING_CYCLES)],
            'project_id' => ['nullable', 'integer', 'exists:customer_projects,id'],
        ]);

        $resellerProductId = ! empty($validated['reseller_product_id']) ? (int) $validated['reseller_product_id'] : null;
        $productId = ! empty($validated['product_id']) ? (int) $validated['product_id'] : null;

        $offer = $this->targets->findOffer($user, $productId, $resellerProductId);
        if ($offer === null) {
            return redirect()->route('customer.deploy-service')->with('error', 'That plan is not available to your account.');
        }

        // Same validation the cart applies; the line itself is added once a
        // stack is chosen, so an abandoned pick leaves nothing in the cart.
        try {
            $offer->resellerProductId
                ? app(CartLineFactory::class)->forResellerProduct($user, $offer->resellerProductId, $validated['billing_cycle'])
                : app(CartLineFactory::class)->forProduct($user, $offer->productId, $validated['billing_cycle']);
        } catch (CartLineException $e) {
            return redirect()->route('customer.deploy-service')->with('error', $e->getMessage());
        }

        $projectId = (int) ($validated['project_id'] ?? 0);
        $project = $projectId > 0 ? CustomerProject::query()->where('user_id', $user->id)->whereKey($projectId)->first() : null;

        session([self::SESSION_PLAN => [
            'product_id' => $offer->productId,
            'reseller_product_id' => $offer->resellerProductId,
            'name' => $offer->name,
            'billing_cycle' => $validated['billing_cycle'],
            'resource_limits' => $offer->resourceLimits,
            'container_template_id' => $offer->pinnedTemplateId,
            'project_id' => $project?->id,
        ]]);

        return redirect()->route('customer.deploy-service.stack');
    }

    public function stack(Request $request): View|RedirectResponse
    {
        $plan = session(self::SESSION_PLAN);
        if (! is_array($plan) || empty($plan['product_id'])) {
            return redirect()->route('customer.deploy-service')->with('error', 'Choose a plan first.');
        }

        $languages = ContainerTemplate::offeredForNewDeploy()->catalogOrder()->get();
        $choices = $this->eligibility->forPlan(
            isset($plan['container_template_id']) ? (int) $plan['container_template_id'] : null,
            StackEligibilityService::limitsFromResourceLimits(is_array($plan['resource_limits'] ?? null) ? $plan['resource_limits'] : []),
            $languages,
        );

        return view('customer.select-techstack', [
            'languages' => $languages,
            'databases' => DatabaseTemplate::active()->get(),
            'cartCount' => count(SessionCart::portal()),
            'attachDomain' => app(SharedHostingCheckoutService::class)->attachDomainFromSession(),
            'plan' => $plan,
            'stackChoices' => $this->eligibility->keyed($choices),
            'stackFormAction' => route('customer.confirm-techstack.store'),
            'selectionSummary' => TechStackRoutingService::class,
        ]);
    }
}
