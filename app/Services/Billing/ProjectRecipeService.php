<?php

namespace App\Services\Billing;

use App\Models\ContainerTemplate;
use App\Models\Product;
use Illuminate\Support\Str;

class ProjectRecipeService
{
    /**
     * @param  array<string, mixed>  $session  selected_techstack session payload
     */
    public function matchKeyFromSession(array $session): ?string
    {
        $slug = strtolower((string) ($session['language_slug'] ?? ''));
        $frontend = strtolower((string) ($session['frontend'] ?? ''));

        if (in_array($frontend, ['next', 'next.js'], true)) {
            $frontend = 'nextjs';
        }

        foreach (config('project_recipes.recipes', []) as $key => $recipe) {
            $match = $recipe['match'] ?? [];
            if (($match['language_slug'] ?? null) === $slug
                && ($match['frontend'] ?? null) === $frontend) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{key: string, label: string, roles: list<array<string, mixed>>}|null
     */
    public function matchRecipeFromSession(array $session): ?array
    {
        $key = $this->matchKeyFromSession($session);
        if ($key === null) {
            return null;
        }

        $recipe = config("project_recipes.recipes.{$key}");
        if (! is_array($recipe)) {
            return null;
        }

        return [
            'key' => $key,
            'label' => (string) ($recipe['label'] ?? $key),
            'roles' => array_values($recipe['roles'] ?? []),
        ];
    }

    public function isProjectRecipeServiceMeta(?array $meta): bool
    {
        $meta = is_array($meta) ? $meta : [];

        return ! empty($meta['project_recipe']) && ! empty($meta['project_role']);
    }

    public function isBillingAnchor(?array $meta): bool
    {
        $meta = is_array($meta) ? $meta : [];

        if (! $this->isProjectRecipeServiceMeta($meta)) {
            return true;
        }

        return (bool) ($meta['project_billing_anchor'] ?? false);
    }

    public function shouldSkipRenewalInvoice(?array $meta): bool
    {
        return $this->isProjectRecipeServiceMeta($meta) && ! $this->isBillingAnchor($meta);
    }

    /**
     * Expand a matched recipe into concrete role definitions for checkout.
     *
     * @param  array<string, mixed>  $session
     * @return list<array{
     *   key: string,
     *   suffix: string,
     *   label: string,
     *   billing_anchor: bool,
     *   service_name: string,
     *   template: ?ContainerTemplate,
     *   provision_template_slug: ?string,
     *   cpu_share: float,
     *   memory_share: float
     * }>
     */
    public function expandRoles(Product $product, array $session, string $projectName): array
    {
        $recipe = $this->matchRecipeFromSession($session);
        if ($recipe === null) {
            return [];
        }

        $baseSlug = $this->projectSlug($projectName);
        $roles = [];

        foreach ($recipe['roles'] as $role) {
            $suffix = (string) ($role['suffix'] ?? $role['key'] ?? 'app');
            $template = null;
            $provisionSlug = null;

            if (($role['template_from'] ?? null) === 'product') {
                $template = $product->containerTemplate;
                if ($template === null) {
                    $template = $this->resolveSessionLanguageTemplate($session);
                }
                $provisionSlug = $template?->slug;
            } elseif (! empty($role['template_slug'])) {
                $provisionSlug = (string) $role['template_slug'];
                $template = ContainerTemplate::query()
                    ->where('slug', $provisionSlug)
                    ->where('is_active', true)
                    ->first();
            }

            $roles[] = [
                'key' => (string) ($role['key'] ?? $suffix),
                'suffix' => $suffix,
                'label' => (string) ($role['label'] ?? ucfirst($suffix)),
                'billing_anchor' => (bool) ($role['billing_anchor'] ?? false),
                'service_name' => $this->roleServiceName($baseSlug, $suffix),
                'template' => $template,
                'provision_template_slug' => $provisionSlug,
                'cpu_share' => (float) ($role['cpu_share'] ?? 0.5),
                'memory_share' => (float) ($role['memory_share'] ?? 0.5),
            ];
        }

        return $roles;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function resolveSessionLanguageTemplate(array $session): ?ContainerTemplate
    {
        $languageId = (int) ($session['language_id'] ?? 0);
        if ($languageId > 0) {
            $byId = ContainerTemplate::query()
                ->where('id', $languageId)
                ->where('is_active', true)
                ->first();
            if ($byId) {
                return $byId;
            }
        }

        $slug = strtolower((string) ($session['language_slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        return ContainerTemplate::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    public function projectSlug(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'project';
        }

        return mb_substr($slug, 0, 40);
    }

    public function roleServiceName(string $projectSlug, string $suffix): string
    {
        $name = $projectSlug.'-'.$suffix;

        return mb_substr($name, 0, 100);
    }

    public function defaultProjectName(array $session, ?string $domainHint = null): string
    {
        if (is_string($domainHint) && trim($domainHint) !== '') {
            $host = preg_replace('/^https?:\/\//', '', strtolower(trim($domainHint))) ?? '';
            $host = rtrim($host, './');
            if ($host !== '') {
                return mb_substr($host, 0, 100);
            }
        }

        $recipe = $this->matchRecipeFromSession($session);

        return $recipe['label'] ?? 'Project';
    }
}
