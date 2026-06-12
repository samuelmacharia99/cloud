<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrarDriver;
use App\Http\Controllers\Controller;
use App\Models\DomainExtension;
use App\Models\Registrar;
use App\Services\Registrar\RegistrarManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegistrarController extends Controller
{
    public function __construct(private RegistrarManager $registrarManager)
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('viewAny', Registrar::class);

            return $next($request);
        });
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Registrar::class);

        $validated = $this->validateRegistrar($request);

        $registrar = DB::transaction(function () use ($validated, $request) {
            if (! empty($validated['is_default'])) {
                Registrar::query()->update(['is_default' => false]);
            }

            $driver = RegistrarDriver::from($validated['driver']);
            $config = $this->extractConfig($request, $driver, []);

            $registrar = Registrar::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'driver' => $driver,
                'environment' => $validated['environment'],
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => $validated['is_default'] ?? false,
                'description' => $validated['description'] ?? null,
                'config' => $config,
                'sort_order' => $validated['sort_order'] ?? 0,
            ]);

            $this->syncTlds($registrar, $validated['tld_ids'] ?? []);

            return $registrar;
        });

        return $this->respond($request, 'Registrar created successfully.', $registrar);
    }

    public function update(Request $request, Registrar $registrar): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $registrar);

        $validated = $this->validateRegistrar($request, $registrar);

        DB::transaction(function () use ($validated, $request, $registrar) {
            if (! empty($validated['is_default'])) {
                Registrar::query()->where('id', '!=', $registrar->id)->update(['is_default' => false]);
            }

            $driver = RegistrarDriver::from($validated['driver']);
            $config = $this->extractConfig($request, $driver, $registrar->config ?? []);

            $registrar->update([
                'name' => $validated['name'],
                'slug' => $registrar->name === $validated['name']
                    ? $registrar->slug
                    : $this->uniqueSlug($validated['name'], $registrar->id),
                'driver' => $driver,
                'environment' => $validated['environment'],
                'is_active' => $validated['is_active'] ?? false,
                'is_default' => $validated['is_default'] ?? false,
                'description' => $validated['description'] ?? null,
                'config' => $config,
                'sort_order' => $validated['sort_order'] ?? $registrar->sort_order,
            ]);

            $this->syncTlds($registrar, $validated['tld_ids'] ?? []);
        });

        return $this->respond($request, 'Registrar updated successfully.', $registrar->fresh('domainExtensions'));
    }

    public function destroy(Request $request, Registrar $registrar): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $registrar);

        DomainExtension::query()
            ->where('registrar_id', $registrar->id)
            ->update(['registrar_id' => null]);

        $registrar->delete();

        return $this->respond($request, 'Registrar removed.');
    }

    public function test(Request $request, Registrar $registrar): RedirectResponse|JsonResponse
    {
        $result = $this->registrarManager->testConnection($registrar);

        $registrar->update([
            'last_tested_at' => now(),
            'last_test_message' => ($result['success'] ? '[OK] ' : '[FAIL] ').$result['message'],
        ]);

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        $key = $result['success'] ? 'success' : 'error';

        return redirect()
            ->route('admin.settings.index', ['tab' => 'registrars'])
            ->with($key, $result['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRegistrar(Request $request, ?Registrar $registrar = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::enum(RegistrarDriver::class)],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'tld_ids' => ['nullable', 'array'],
            'tld_ids.*' => ['integer', 'exists:domain_extensions,id'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function extractConfig(Request $request, RegistrarDriver $driver, array $existing): array
    {
        $config = $existing;
        $incoming = $request->input('config', []);

        foreach ($driver->configFields() as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];

            if ($field['type'] === 'password' && ($value === null || $value === '')) {
                continue;
            }

            if ($value === '••••••••') {
                continue;
            }

            $config[$key] = is_string($value) ? trim($value) : $value;
        }

        return $config;
    }

    /**
     * @param  list<int>  $tldIds
     */
    private function syncTlds(Registrar $registrar, array $tldIds): void
    {
        DomainExtension::query()
            ->where('registrar_id', $registrar->id)
            ->whereNotIn('id', $tldIds)
            ->update([
                'registrar_id' => null,
            ]);

        if ($tldIds === []) {
            return;
        }

        DomainExtension::query()
            ->whereIn('id', $tldIds)
            ->update([
                'registrar_id' => $registrar->id,
                'registrar' => $registrar->slug,
            ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'registrar';
        $slug = $base;
        $counter = 1;

        while (
            Registrar::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    private function respond(Request $request, string $message, ?Registrar $registrar = null): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'registrar' => $registrar?->load('domainExtensions')->toAdminArray(),
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'registrars'])
            ->with('success', $message);
    }
}
