<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DaConvertBatchItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CutoverDaConvertBatchRequest;
use App\Http\Requests\Admin\ImportDaResellerPackagesRequest;
use App\Http\Requests\Admin\QueueDaConvertBatchRequest;
use App\Http\Requests\Admin\RetryDaConvertAccountRequest;
use App\Models\DaConvertBatch;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DaResellerPackageImportService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DaConvertOfframpController extends Controller
{
    public function show(
        User $user,
        DaConvertOfframpService $offramp,
        DirectAdminToContainerConvertService $convert,
        DaResellerPackageImportService $packages,
    ): View {
        abort_if(! $user->is_reseller, 404);

        foreach ($offramp->openConvertItems($user) as $item) {
            if ($item->service?->containerDeployment) {
                $offramp->refreshCutoverStatus($item);
            }
        }

        $accounts = $offramp->boardAccounts($user);
        $services = $accounts
            ->pluck('service')
            ->filter()
            ->values();
        $packageMap = $accounts->mapWithKeys(function (array $account) use ($packages, $user): array {
            if ($account['service'] instanceof Service) {
                return [$account['key'] => $packages->resolveForService($user, $account['service'])];
            }

            return [$account['key'] => $packages->resolveForPackageName($user, (string) ($account['package'] ?? ''))];
        });

        $catalog = $convert->applicationHostingCatalog();
        $emailProducts = Product::query()
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->where('provisioning_driver_key', 'mailcow')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return view('admin.resellers.directadmin-offramp', [
            'reseller' => $user,
            'services' => $services,
            'accounts' => $accounts,
            'packageMap' => $packageMap,
            'containerProducts' => $catalog['products'],
            'emailProducts' => $emailProducts,
            'convertProgress' => $offramp->operatorProgress($user),
        ]);
    }

    public function progress(
        User $user,
        DaConvertOfframpService $offramp,
    ): JsonResponse {
        abort_if(! $user->is_reseller, 404);

        return response()->json($offramp->operatorProgress($user));
    }

    public function importPackages(
        ImportDaResellerPackagesRequest $request,
        User $user,
        DaResellerPackageImportService $packages,
    ): RedirectResponse {
        abort_if(! $user->is_reseller, 404);

        try {
            $result = $packages->import($user, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.resellers.directadmin-offramp', $user)
            ->with('success', sprintf(
                'Imported DirectAdmin packages into %s’s catalog: %d created, %d updated. Customer prices were left as they already sell them.',
                $user->name,
                $result['created'],
                $result['updated']
            ));
    }

    public function store(
        QueueDaConvertBatchRequest $request,
        User $user,
        DaConvertOfframpService $offramp,
    ): RedirectResponse {
        abort_if(! $user->is_reseller, 404);

        try {
            $batch = $offramp->queueBatch(
                $user,
                $request->user(),
                $request->validated('service_ids') ?? [],
                $request->applicationHostingProduct(),
                $request->emailHostingProduct(),
                $request->boolean('acknowledge_mail_pull'),
                $request->boolean('acknowledge_addon_sites'),
                $request->validated('account_keys') ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $queued = $batch->items->where('status', DaConvertBatchItemStatus::Queued)->count()
            + $batch->items->where('status', DaConvertBatchItemStatus::Converting)->count();
        $skipped = $batch->items->count() - $queued;

        return redirect()
            ->route('admin.resellers.directadmin-offramp', $user)
            ->with('success', sprintf(
                'Queued %d account(s) (%d skipped). Converts run one at a time on this DirectAdmin node.',
                $queued,
                max(0, $skipped)
            ));
    }

    public function retry(
        RetryDaConvertAccountRequest $request,
        User $user,
        DaConvertOfframpService $offramp,
    ): RedirectResponse {
        abort_if(! $user->is_reseller, 404);

        try {
            $batch = $offramp->queueBatch(
                $user,
                $request->user(),
                [],
                $request->applicationHostingProduct(),
                $request->emailHostingProduct(),
                $request->boolean('acknowledge_mail_pull', true),
                $request->boolean('acknowledge_addon_sites', true),
                [$request->validated('account_key')],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        } catch (\DomainException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $item = $batch->items->first();
        $label = $item?->hostname ?: $request->validated('account_key');

        if ($item?->status === DaConvertBatchItemStatus::Queued || $item?->status === DaConvertBatchItemStatus::Converting) {
            return redirect()
                ->route('admin.resellers.directadmin-offramp', $user)
                ->with('success', 'Retry queued for '.$label.'.');
        }

        return redirect()
            ->route('admin.resellers.directadmin-offramp', $user)
            ->withErrors(['error' => $item?->error ?: 'Could not retry this account.']);
    }

    public function cutDns(
        CutoverDaConvertBatchRequest $request,
        User $user,
        DaConvertBatch $batch,
        DaConvertOfframpService $offramp,
    ): RedirectResponse {
        abort_if(! $user->is_reseller, 404);
        abort_if((int) $batch->reseller_user_id !== (int) $user->id, 404);

        $result = $offramp->cutWebDns($batch, $request->validated('item_ids'));

        return redirect()
            ->route('admin.resellers.directadmin-offramp', $user)
            ->with('success', sprintf(
                'Cut web DNS for %d account(s). %d skipped (not converted yet, or no hostname).',
                $result['updated'],
                $result['skipped']
            ));
    }
}
