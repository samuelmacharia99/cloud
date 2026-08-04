<?php

namespace App\Services\Checkout;

use App\Models\CustomerProject;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ProjectRecipeService;
use App\Services\Customer\CustomerProjectService;

class ProjectHostingCheckoutService
{
    public function __construct(
        private ProjectRecipeService $recipes,
        private CustomerProjectService $projects,
    ) {}

    /**
     * When the tech-stack session matches a project recipe, create a CustomerProject
     * with role services and a single invoice line on the billing anchor.
     *
     * @param  array<string, mixed>  $item  cart line
     * @param  array<string, mixed>  $baseServiceMeta
     * @return array{project: CustomerProject, billing_service: Service, services: list<Service>}|null
     */
    public function createFromCartItem(
        User $user,
        Product $product,
        Order $order,
        OrderItem $orderItem,
        Invoice $invoice,
        array $item,
        array $baseServiceMeta,
        ?string $domainHint = null,
    ): ?array {
        $session = session('selected_techstack', []);
        if (! is_array($session) || $session === []) {
            return null;
        }

        $recipe = $this->recipes->matchRecipeFromSession($session);
        if ($recipe === null) {
            return null;
        }

        $roles = $this->recipes->expandRoles(
            $product->loadMissing('containerTemplate'),
            $session,
            $this->recipes->defaultProjectName($session, $domainHint),
        );

        if (count($roles) < 2) {
            return null;
        }

        foreach ($roles as $role) {
            if (($role['billing_anchor'] ?? false) && ($role['template'] ?? null) === null) {
                return null;
            }
            if (! ($role['billing_anchor'] ?? false) && ($role['template'] ?? null) === null) {
                return null;
            }
        }

        $projectName = $this->recipes->defaultProjectName($session, $domainHint);
        $project = CustomerProject::create([
            'user_id' => $user->id,
            'name' => mb_substr($projectName, 0, 100),
            'recipe_key' => $recipe['key'],
            'resource_pool' => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'roles' => collect($roles)->map(fn (array $r) => [
                    'key' => $r['key'],
                    'cpu_share' => $r['cpu_share'],
                    'memory_share' => $r['memory_share'],
                ])->values()->all(),
            ],
        ]);

        $provisioningDriver = $product->provisioning_driver_key ?: 'container';
        $nextDue = now()->addMonths($this->billingCycleMonths((string) ($item['billing_cycle'] ?? 'monthly')));
        $created = [];
        $byKey = [];

        foreach ($roles as $role) {
            $meta = array_merge($baseServiceMeta, [
                'project_recipe' => $recipe['key'],
                'project_role' => $role['key'],
                'project_role_label' => $role['label'],
                'project_billing_anchor' => $role['billing_anchor'],
                'provision_template_slug' => $role['provision_template_slug'],
                'resource_share' => [
                    'cpu' => $role['cpu_share'],
                    'memory' => $role['memory_share'],
                ],
            ]);

            // Non-anchor roles should not re-trigger legacy Next sidecar intent on the API service.
            if (! $role['billing_anchor']) {
                $meta['frontend'] = $meta['frontend'] ?? 'nextjs';
            }

            $service = Service::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'project_id' => $project->id,
                'order_item_id' => $orderItem->id,
                'invoice_id' => $invoice->id,
                'reseller_id' => $item['reseller_id'] ?? $user->reseller_id,
                'name' => $role['service_name'],
                'status' => 'pending',
                'billing_cycle' => $item['billing_cycle'],
                'custom_price' => $role['billing_anchor'] ? $item['unit_price'] : 0,
                'next_due_date' => $nextDue,
                'provisioning_driver_key' => $provisioningDriver,
                'node_id' => null,
                'service_meta' => $meta,
            ]);

            $created[] = $service;
            $byKey[$role['key']] = $service;
        }

        $backend = $byKey['backend'] ?? $created[0];
        $frontend = $byKey['frontend'] ?? ($created[1] ?? null);

        foreach ($created as $service) {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['backend_service_id'] = $backend->id;
            if ($frontend) {
                $meta['frontend_service_id'] = $frontend->id;
                $meta['sibling_service_id'] = (int) $service->id === (int) $backend->id
                    ? $frontend->id
                    : $backend->id;
            }
            $service->service_meta = $meta;
            $service->save();
        }

        $project->billing_service_id = $backend->id;
        $project->save();

        $roleLabels = collect($roles)->pluck('label')->implode(' + ');
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $backend->id,
            'product_id' => $product->id,
            'description' => "{$project->name} ({$roleLabels}) — {$product->name}",
            'quantity' => 1,
            'unit_price' => $item['unit_price'],
            'amount' => $item['amount'],
        ]);

        $this->projects->syncRelated($backend->fresh());

        return [
            'project' => $project->fresh(),
            'billing_service' => $backend->fresh(),
            'services' => $created,
        ];
    }

    private function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }
}
