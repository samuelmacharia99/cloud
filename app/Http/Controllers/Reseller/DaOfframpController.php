<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\DaConvertBatchItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reseller\CutoverDaConvertBatchRequest;
use App\Http\Requests\Reseller\QueueDaConvertBatchRequest;
use App\Http\Requests\Reseller\RelinkDaAccountRequest;
use App\Http\Requests\Reseller\RetryDaConvertAccountRequest;
use App\Models\DaConvertBatch;
use App\Models\DaConvertBatchItem;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DaConvertOfframpService;
use App\Services\Provisioning\DaResellerPackageImportService;
use App\Services\ResellerComputeUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The reseller's own DirectAdmin off-ramp: the same engine the platform
 * board uses, scoped to the signed-in reseller's book and their own plans.
 */
class DaOfframpController extends Controller
{
    public function show(
        Request $request,
        DaConvertOfframpService $offramp,
        DaResellerPackageImportService $packages,
    ): View {
        $reseller = $this->reseller($request);

        $refresh = $request->boolean('refresh');
        if ($refresh) {
            foreach ($offramp->openConvertItems($reseller) as $item) {
                if (! $item->service?->containerDeployment) {
                    continue;
                }
                if (! in_array($item->status, [
                    DaConvertBatchItemStatus::Converted,
                    DaConvertBatchItemStatus::WaitingDns,
                    DaConvertBatchItemStatus::WaitingMx,
                ], true)) {
                    continue;
                }
                $offramp->refreshCutoverStatus($item);
            }
        }

        $accounts = $offramp->boardAccounts($reseller, $refresh);
        $packageMap = $accounts->mapWithKeys(function (array $account) use ($packages, $reseller): array {
            if ($account['service'] instanceof Service) {
                return [$account['key'] => $packages->resolveForService($reseller, $account['service'])];
            }

            return [$account['key'] => $packages->resolveForPackageName($reseller, (string) ($account['package'] ?? ''))];
        });

        $plans = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('type', 'container_hosting')
            ->where('is_active', true)
            ->with('adminProduct.containerTemplate')
            ->orderBy('monthly_price')
            ->orderBy('name')
            ->get()
            ->filter(fn (ResellerProduct $listing) => $packages->engineForListing($listing, null) !== null)
            ->values();

        $emailPlans = ResellerProduct::query()
            ->where('reseller_id', $reseller->id)
            ->where('type', 'email_hosting')
            ->where('is_active', true)
            ->with('adminProduct')
            ->get()
            ->filter(fn (ResellerProduct $listing) => $listing->adminProduct?->provisioning_driver_key === 'mailcow' && $listing->adminProduct->is_active)
            ->values();

        return view('reseller.services.offramp', [
            'reseller' => $reseller,
            'accounts' => $accounts,
            'daUsernames' => $offramp->unlinkedDirectAdminUsernames($reseller),
            'daNodes' => $offramp->resellerDirectAdminNodes($reseller),
            'daListing' => $offramp->liveDirectAdminListingStatus($reseller),
            'packageMap' => $packageMap,
            'plans' => $plans,
            'emailPlans' => $emailPlans,
            'computePool' => app(ResellerComputeUsageService::class)->poolPresentation($reseller),
            'convertProgress' => $offramp->operatorProgress($reseller, true),
        ]);
    }

    public function progress(Request $request, DaConvertOfframpService $offramp): JsonResponse
    {
        return response()->json($offramp->operatorProgress($this->reseller($request), true));
    }

    public function importPackages(Request $request, DaResellerPackageImportService $packages): RedirectResponse
    {
        $reseller = $this->reseller($request);

        try {
            $result = $packages->import($reseller, $reseller);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('reseller.directadmin-offramp')
            ->with('success', sprintf(
                'Imported your DirectAdmin packages into your catalogue: %d created, %d updated. Prices were left as you already sell them.',
                $result['created'],
                $result['updated']
            ));
    }

    public function store(QueueDaConvertBatchRequest $request, DaConvertOfframpService $offramp): RedirectResponse
    {
        $reseller = $this->reseller($request);

        try {
            $batch = $offramp->queueBatch(
                $reseller,
                $reseller,
                [],
                $request->fallbackEngine(),
                $request->emailHostingProduct(),
                $request->boolean('acknowledge_mail_pull'),
                $request->boolean('acknowledge_addon_sites'),
                $request->validated('account_keys') ?? [],
                $request->planChoices(),
                $request->fallbackListing(),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $queued = $batch->items->where('status', DaConvertBatchItemStatus::Queued)->count()
            + $batch->items->where('status', DaConvertBatchItemStatus::Converting)->count();
        $skipped = $batch->items->count() - $queued;

        return redirect()
            ->route('reseller.directadmin-offramp')
            ->with('success', sprintf(
                'Queued %d account(s) (%d skipped). Moves run one at a time; the log at the bottom right follows each step.',
                $queued,
                max(0, $skipped)
            ));
    }

    public function retry(RetryDaConvertAccountRequest $request, DaConvertOfframpService $offramp): RedirectResponse
    {
        $reseller = $this->reseller($request);

        try {
            $batch = $offramp->queueBatch(
                $reseller,
                $reseller,
                [],
                $request->fallbackEngine(),
                $request->emailHostingProduct(),
                $request->boolean('acknowledge_mail_pull', true),
                $request->boolean('acknowledge_addon_sites', true),
                [$request->validated('account_key')],
                $request->planChoices(),
                $request->fallbackListing(),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $item = $batch->items->first();
        $label = $item?->hostname ?: $request->validated('account_key');

        if ($item?->status === DaConvertBatchItemStatus::Queued || $item?->status === DaConvertBatchItemStatus::Converting) {
            return redirect()
                ->route('reseller.directadmin-offramp')
                ->with('success', 'Retrying '.$label.'. The log at the bottom right follows each step.');
        }

        return redirect()
            ->route('reseller.directadmin-offramp')
            ->withErrors(['error' => $item?->error ?: ('Could not retry '.$label.'.')]);
    }

    /**
     * The DNS snapshot found no such DirectAdmin user: the reseller names the
     * right user and server for the service, and the move is queued again.
     */
    public function relink(RelinkDaAccountRequest $request, Service $service, DaConvertOfframpService $offramp): RedirectResponse
    {
        $reseller = $this->reseller($request);

        try {
            $result = $offramp->relinkDirectAdminAccount(
                $reseller,
                $reseller,
                $service,
                (string) $request->validated('directadmin_username'),
                $request->filled('node_id') ? (int) $request->validated('node_id') : null,
                $request->boolean('retry', true),
            );
        } catch (\InvalidArgumentException $e) {
            abort(404);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $redirect = redirect()->route('reseller.directadmin-offramp');

        return $result['ok']
            ? $redirect->with('success', $result['message'])
            : $redirect->withErrors(['error' => $result['message']])->withInput();
    }

    /**
     * Pull a converted account from DirectAdmin again, from scratch.
     */
    public function repull(Request $request, Service $service, DaConvertOfframpService $offramp): RedirectResponse
    {
        $reseller = $this->reseller($request);
        if (! $request->boolean('confirm_wipe')) {
            return redirect()->route('reseller.directadmin-offramp')
                ->withErrors(['error' => 'Tick the confirmation: a fresh pull removes the container, its database and every sibling site before pulling again.']);
        }

        try {
            $result = $offramp->repullFromDirectAdmin($reseller, $reseller, $service);
        } catch (\InvalidArgumentException $e) {
            abort(404);
        } catch (\Throwable $e) {
            return redirect()->route('reseller.directadmin-offramp')->withErrors(['error' => 'Could not pull again: '.$e->getMessage()]);
        }

        $redirect = redirect()->route('reseller.directadmin-offramp');

        return $result['ok']
            ? $redirect->with('success', $result['message'])
            : $redirect->withErrors(['error' => $result['message']]);
    }

    /**
     * Restart one convert from the console log: a stalled queued or converting
     * item is re-dispatched, a blocked or failed one goes back through preflight.
     */
    public function restart(
        Request $request,
        DaConvertBatch $batch,
        DaConvertBatchItem $item,
        DaConvertOfframpService $offramp,
    ): JsonResponse|RedirectResponse {
        $reseller = $this->reseller($request);
        abort_if((int) $batch->reseller_user_id !== (int) $reseller->id, 404);
        abort_if((int) $item->da_convert_batch_id !== (int) $batch->id, 404);

        try {
            $result = $offramp->restartItem($reseller, $reseller, $item);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'batch_id' => $batch->id, 'item_id' => $item->id];
        }

        if ($request->expectsJson()) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        $redirect = redirect()->route('reseller.directadmin-offramp');

        return $result['ok']
            ? $redirect->with('success', $result['message'])
            : $redirect->withErrors(['error' => $result['message']]);
    }

    public function cutDns(CutoverDaConvertBatchRequest $request, DaConvertBatch $batch, DaConvertOfframpService $offramp): RedirectResponse
    {
        $reseller = $this->reseller($request);
        abort_if((int) $batch->reseller_user_id !== (int) $reseller->id, 404);

        $result = $offramp->cutWebDns($batch, $request->validated('item_ids'));

        return redirect()
            ->route('reseller.directadmin-offramp')
            ->with('success', sprintf(
                'Cut web DNS for %d account(s). %d skipped (not moved yet, or no hostname).',
                $result['updated'],
                $result['skipped']
            ));
    }

    private function reseller(Request $request): User
    {
        $user = $request->user();
        abort_if(! $user || ! $user->is_reseller, 404);

        return $user;
    }
}
