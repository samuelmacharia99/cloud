<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DaConvertBatchItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CutoverDaConvertBatchRequest;
use App\Http\Requests\Admin\ImportDaResellerPackagesRequest;
use App\Http\Requests\Admin\QueueDaConvertBatchRequest;
use App\Models\DaConvertBatch;
use App\Models\Product;
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

        $services = $offramp->eligibleServices($user);
        $packageMap = $packages->mappingForServices($user, $services);
        $batches = DaConvertBatch::query()
            ->where('reseller_user_id', $user->id)
            ->with(['items.service.user', 'items.service.containerDeployment.node', 'items.service.containerDeployment.domains', 'product'])
            ->latest()
            ->limit(8)
            ->get();

        foreach ($batches as $batch) {
            foreach ($batch->items as $item) {
                if ($item->service?->containerDeployment) {
                    $offramp->refreshCutoverStatus($item);
                }
            }
            $offramp->refreshBatchStatus($batch);
        }

        $batches->load(['items.service.user', 'product']);

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
            'packageMap' => $packageMap->keyBy(fn (array $row) => (int) $row['service']->id),
            'batches' => $batches,
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
                $request->validated('service_ids'),
                $request->applicationHostingProduct(),
                $request->emailHostingProduct(),
                $request->boolean('acknowledge_mail_pull'),
                $request->boolean('acknowledge_addon_sites'),
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
                'Batch #%d queued %d account(s) on the da-convert queue (%d skipped as blocked or needing acknowledgement). Run queue:work --queue=da-convert --timeout=2400.',
                $batch->id,
                $queued,
                max(0, $skipped)
            ));
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
