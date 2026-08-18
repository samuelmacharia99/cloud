<?php

namespace App\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainRenewalOrder;
use App\Models\Registrar;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Services\DomainPushService;
use App\Services\DomainRenewalService;
use App\Services\DomainTransferService;
use App\Services\NodeNameserverService;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Drivers\CosmotownRegistrarDriver;
use App\Services\Registrar\Drivers\OpenproviderRegistrarDriver;
use App\Services\Registrar\Openprovider\OpenproviderClient;
use App\Services\Registrar\Openprovider\OpenproviderException;
use Illuminate\Support\Facades\Log;

class RegistrarFulfillmentService
{
    public function __construct(
        private RegistrarManager $registrarManager,
        private NodeNameserverService $nameserverService,
    ) {}

    public function fulfillOrder(ResellerDomainOrder $order): void
    {
        $this->attemptAutoFulfillment($order);
    }

    /**
     * Submit a paid registration to the API registrar when push_mode is auto.
     *
     * @return array{success: bool, message: string}
     */
    public function attemptAutoFulfillment(ResellerDomainOrder $order): array
    {
        $order->refresh();

        if ($order->status !== 'pushed' || ($order->push_mode ?? 'auto') !== 'auto') {
            return ['success' => false, 'message' => 'Order not eligible for automatic registrar fulfillment.'];
        }

        if ($order->isTransfer()) {
            return ['success' => false, 'message' => 'Transfers are fulfilled separately.'];
        }

        return $this->runOrderFulfillment($order, manual: false);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function fulfillOrderManually(ResellerDomainOrder $order): array
    {
        $order->refresh();

        if (! $order->canAdminPushToRegistrar()) {
            return [
                'success' => false,
                'message' => 'This order cannot be submitted to the registrar. Push it to admin first, or the domain is already active at the registrar.',
            ];
        }

        if ($order->status === 'failed') {
            $order->update([
                'status' => 'pushed',
                'failed_at' => null,
                'failure_reason' => null,
            ]);
        }

        return $this->runOrderFulfillment($order->fresh(['domain']), manual: true);
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function runOrderFulfillment(ResellerDomainOrder $order, bool $manual): array
    {
        $order->loadMissing('domain.domainExtension.registrarModel');

        $domain = $order->domain;
        if (! $domain) {
            return $manual
                ? ['success' => false, 'message' => 'No domain record is linked to this order.']
                : ['success' => false, 'message' => ''];
        }

        if ($domain->isLinkedToRegistrarApi() && in_array($domain->status, ['pending', 'active'], true)) {
            if ($manual) {
                return ['success' => false, 'message' => 'This domain already has an active registrar submission.'];
            }

            return ['success' => false, 'message' => ''];
        }

        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver) {
            $message = 'No API registrar is configured for this TLD.';

            if ($manual) {
                return ['success' => false, 'message' => $message];
            }

            $this->failRegistrarSubmission($order, $message);

            return ['success' => false, 'message' => $message];
        }

        $resolvedNameservers = $this->nameserverService->forDomain($domain);
        $nameServers = OpenproviderClient::nameServerRecords($resolvedNameservers);

        if (count($nameServers) < 2) {
            $resolved = $this->nameserverService->forDomain($domain);
            throw new \RuntimeException(
                'At least two unique nameservers are required. Set distinct NS1 and NS2 on the linked container node '
                .'(Admin → Settings → Provisioning), ensure platform fallback NS2 is configured, then retry. '
                .'Resolved: '.implode(', ', $this->nameserverService->uniqueList($resolved)).'.'
            );
        }

        try {
            if ($order->isTransfer()) {
                $authCode = $domain->epp_code
                    ?? $domain->transfer_authorization_code
                    ?? '';

                if ($authCode === '') {
                    throw new \RuntimeException('EPP / auth code is required for transfer.');
                }

                $result = $driver->transferDomain($registrar, $domain, $authCode, $nameServers);
            } else {
                $result = $driver->registerDomain($registrar, $domain, (int) $order->years, $nameServers);
            }

            $this->applyOperationResult($domain, $registrar, $result, $order);

            $order->refresh();
            $domain->refresh();

            if ($order->status === 'completed') {
                return [
                    'success' => true,
                    'message' => "Domain {$order->fullDomainName()} registered at {$registrar->name} (active).",
                ];
            }

            if ($order->status === 'failed') {
                return [
                    'success' => false,
                    'message' => $order->failure_reason ?? 'Registrar rejected the request.',
                ];
            }

            $status = strtoupper((string) ($result['status'] ?? 'REQ'));

            return [
                'success' => true,
                'message' => "Submitted to {$registrar->name}. Registrar status: {$status}. "
                    .($status === 'REQ' ? 'It will complete automatically when the registry activates the domain.' : ''),
            ];
        } catch (\Throwable $e) {
            $context = [
                'order_id' => $order->id,
                'domain' => $domain->name.$domain->extension,
                'error' => $e->getMessage(),
            ];

            if ($e instanceof OpenproviderException) {
                $context['api_code'] = $e->apiCode;
                $context['response'] = $e->response;
            }

            if ($e instanceof CosmotownException) {
                $context['http_status'] = $e->httpStatus;
                $context['response'] = $e->response;
            }

            Log::error('Registrar fulfillment failed', $context);

            $this->failRegistrarSubmission($order->fresh(), $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function failRegistrarSubmission(ResellerDomainOrder $order, string $reason): void
    {
        app(DomainPushService::class)->failRegistrarSubmission($order, $reason);
    }

    public function fulfillStandaloneTransfer(Domain $domain): void
    {
        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver) {
            DomainTransferService::initiateTransfer($domain);

            return;
        }

        $authCode = $domain->epp_code ?? $domain->transfer_authorization_code ?? '';
        if ($authCode === '') {
            Log::warning('Standalone transfer missing auth code', ['domain_id' => $domain->id]);

            return;
        }

        $resolvedNameservers = $this->nameserverService->forDomain($domain);
        $nameServers = OpenproviderClient::nameServerRecords($resolvedNameservers);

        if (count($nameServers) < 2) {
            Log::warning('Standalone transfer missing required unique nameservers', [
                'domain_id' => $domain->id,
                'nameservers' => $resolvedNameservers,
            ]);

            return;
        }

        try {
            $result = $driver->transferDomain($registrar, $domain, $authCode, $nameServers);
            $this->applyTransferResult($domain, $registrar, $result);
        } catch (\Throwable $e) {
            Log::error('Standalone transfer API failed', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);
            DomainTransferService::failTransfer($domain, $e->getMessage());
        }
    }

    public function fulfillRenewal(DomainRenewalOrder $renewalOrder): void
    {
        $renewalOrder->loadMissing('domain.domainExtension.registrarModel');
        $domain = $renewalOrder->domain;

        if (! $domain) {
            return;
        }

        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver) {
            return;
        }

        try {
            $result = $driver->renewDomain($registrar, $domain, (int) $renewalOrder->years);

            if (! $result['success']) {
                app(DomainRenewalService::class)->failRenewal($renewalOrder, $result['message']);

                return;
            }

            if (($result['status'] ?? '') === 'ACT') {
                app(DomainRenewalService::class)->completeRenewal(
                    $renewalOrder,
                    'Renewed automatically via '.$registrar->name.'.',
                );

                if ($expiry = OpenproviderRegistrarDriver::parseExpiration($result['expiration_date'] ?? null)) {
                    $domain->update(['expires_at' => $expiry]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Registrar renewal failed', [
                'renewal_order_id' => $renewalOrder->id,
                'error' => $e->getMessage(),
            ]);
            app(DomainRenewalService::class)->failRenewal($renewalOrder, $e->getMessage());
        }
    }

    public function syncDomain(Domain $domain): bool
    {
        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver) {
            return false;
        }

        $result = $driver->syncDomainStatus($registrar, $domain);

        if (! ($result['success'] ?? false)) {
            return false;
        }

        $updates = [];

        $this->storeRemoteRegistrarId($updates, $result['external_id'] ?? null);

        if ($expiry = OpenproviderRegistrarDriver::parseExpiration($result['expiration_date'] ?? null)) {
            $updates['expires_at'] = $expiry;
        }

        $status = strtoupper((string) ($result['status'] ?? ''));

        if ($status === 'ACT') {
            $updates['status'] = 'active';
            if ($domain->isTransfer() && $domain->transfer_status !== 'completed') {
                DomainTransferService::completeTransfer($domain, $registrar->name);
            }
        } elseif ($status === 'REQ') {
            $updates['status'] = $domain->isTransfer() ? $domain->status : 'pending';
            if ($domain->isTransfer()) {
                $updates['transfer_status'] = 'in_progress';
            }
        } elseif ($status === 'FAI') {
            if ($domain->isTransfer()) {
                DomainTransferService::failTransfer($domain, 'Registrar reported transfer failure.');

                return false;
            }

            $updates['status'] = 'pending';
        }

        if ($updates !== []) {
            $domain->update($updates);
        }

        if ($status === 'FAI' && ! $domain->isTransfer()) {
            app(DomainPushService::class)->failRegistrarSubmissionsForDomain(
                $domain->fresh(),
                'Registrar reported registration failure.',
            );
        }

        return $status === 'ACT';
    }

    /**
     * Push nameserver changes to the registrar when the domain is registered there.
     *
     * @param  array{ns1?: string, ns2?: ?string, ns3?: ?string, ns4?: ?string}|list<string>  $nameservers
     * @return array{success: bool, message: string, pushed: bool}
     */
    public function updateDomainNameservers(Domain $domain, array $nameservers): array
    {
        $nameServers = OpenproviderClient::nameServerRecords($nameservers);

        if (count($nameServers) < 2) {
            return [
                'success' => false,
                'pushed' => false,
                'message' => 'At least two unique nameservers are required.',
            ];
        }

        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver || ! $domain->isLinkedToRegistrarApi()) {
            return [
                'success' => true,
                'pushed' => false,
                'message' => 'Nameservers saved locally. This domain is not yet linked to a registrar API record, so the registry was not updated.',
            ];
        }

        try {
            $result = $driver->updateNameservers($registrar, $domain, $nameServers);

            if (! ($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'pushed' => false,
                    'message' => $result['message'] ?? 'Registrar rejected the nameserver update.',
                ];
            }

            return [
                'success' => true,
                'pushed' => true,
                'message' => $result['message'] ?? 'Nameservers updated at the registrar. DNS changes may take up to 48 hours to propagate.',
            ];
        } catch (\Throwable $e) {
            Log::error('Nameserver update failed', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'pushed' => false,
                'message' => 'Could not update nameservers at the registrar: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Load live nameservers and EPP/auth code from the registry, persist them locally,
     * and return reseller-safe copy (never names Cosmotown or Openprovider).
     *
     * @return array{
     *     nameservers: array{nameserver_1: ?string, nameserver_2: ?string, nameserver_3: ?string, nameserver_4: ?string},
     *     epp_code: ?string,
     *     nameservers_live: bool,
     *     epp_live: bool,
     *     message: ?string
     * }
     */
    public function refreshLiveRegistryDetails(Domain $domain): array
    {
        $local = $this->localNameserverColumns($domain);
        $localEpp = filled($domain->epp_code) ? (string) $domain->epp_code : null;

        $fallback = [
            'nameservers' => $local,
            'epp_code' => $localEpp,
            'nameservers_live' => false,
            'epp_live' => false,
            'message' => null,
        ];

        if ($domain->isDnsManaged()) {
            return $fallback;
        }

        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver || ! $registrar) {
            return $fallback;
        }

        $updates = [];
        $liveNs = false;
        $liveEpp = false;
        $attempted = false;

        if ($driver instanceof CosmotownRegistrarDriver) {
            $attempted = true;

            try {
                $hosts = $driver->liveNameservers($registrar, $domain);
                if ($hosts !== []) {
                    $updates = array_merge($updates, $this->nameserverColumnsFromList($hosts));
                    $liveNs = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Live registry nameservers failed', [
                    'domain_id' => $domain->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $auth = $driver->getDomainAuthCode($registrar, $domain);
                if (($auth['success'] ?? false) && filled($auth['auth_code'] ?? null)) {
                    $updates['epp_code'] = (string) $auth['auth_code'];
                    $liveEpp = true;
                }
            } catch (\Throwable $e) {
                Log::warning('Live registry auth code failed', [
                    'domain_id' => $domain->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($updates !== []) {
            $domain->update($updates);
            $domain->refresh();
        }

        $message = null;
        if ($attempted && ! $liveNs && ! $liveEpp) {
            $message = 'Could not refresh this domain from the registry just now. Showing the last saved values.';
        }

        return [
            'nameservers' => $this->localNameserverColumns($domain),
            'epp_code' => filled($domain->epp_code) ? (string) $domain->epp_code : $localEpp,
            'nameservers_live' => $liveNs,
            'epp_live' => $liveEpp,
            'message' => $message,
        ];
    }

    public function concealProviderMessage(string $message): string
    {
        $cleaned = preg_replace('/https?:\/\/[^\s]*cosmotown[^\s]*/i', 'the registry', $message) ?? $message;
        $cleaned = preg_replace('/\b(cosmotown|openprovider)\b/i', 'the registry', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s{2,}/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    /**
     * @return array{nameserver_1: ?string, nameserver_2: ?string, nameserver_3: ?string, nameserver_4: ?string}
     */
    private function localNameserverColumns(Domain $domain): array
    {
        return [
            'nameserver_1' => $domain->nameserver_1,
            'nameserver_2' => $domain->nameserver_2,
            'nameserver_3' => $domain->nameserver_3,
            'nameserver_4' => $domain->nameserver_4,
        ];
    }

    /**
     * @param  list<string>  $hosts
     * @return array{nameserver_1: ?string, nameserver_2: ?string, nameserver_3: ?string, nameserver_4: ?string}
     */
    private function nameserverColumnsFromList(array $hosts): array
    {
        $hosts = array_values($hosts);

        return [
            'nameserver_1' => $hosts[0] ?? null,
            'nameserver_2' => $hosts[1] ?? null,
            'nameserver_3' => $hosts[2] ?? null,
            'nameserver_4' => $hosts[3] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyOperationResult(Domain $domain, Registrar $registrar, array $result, ResellerDomainOrder $order): void
    {
        if (! ($result['success'] ?? false)) {
            $message = $result['message'] ?? 'Registrar rejected the request.';

            if ($order->isTransfer()) {
                DomainTransferService::failTransfer($domain, $message);
            } else {
                $this->failRegistrarSubmission($order, $message);
            }

            return;
        }

        $this->persistDomainRegistrarData($domain, $registrar, $result);

        $status = strtoupper((string) ($result['status'] ?? ''));

        if ($status === 'ACT') {
            if ($order->isTransfer()) {
                DomainTransferService::completeTransfer($domain->fresh(), $registrar->name);
            }

            app(DomainPushService::class)->completeOrder($order->fresh(), $registrar->name);

            return;
        }

        if ($order->isTransfer()) {
            DomainTransferService::markInProgress($domain->fresh());
        } else {
            $domain->update(['status' => 'pending']);
            $this->activateLinkedServices($order);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applyTransferResult(Domain $domain, Registrar $registrar, array $result): void
    {
        if (! ($result['success'] ?? false)) {
            DomainTransferService::failTransfer($domain, $result['message'] ?? 'Transfer rejected by registrar.');

            return;
        }

        $this->persistDomainRegistrarData($domain, $registrar, $result);

        $status = strtoupper((string) ($result['status'] ?? ''));

        if ($status === 'ACT') {
            DomainTransferService::completeTransfer($domain->fresh(), $registrar->name);
        } else {
            DomainTransferService::markInProgress($domain->fresh());
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistDomainRegistrarData(Domain $domain, Registrar $registrar, array $result): void
    {
        $updates = [
            'registrar' => $registrar->slug,
        ];

        $this->storeRemoteRegistrarId($updates, $result['external_id'] ?? null);

        if (! empty($result['auth_code'])) {
            $updates['epp_code'] = $result['auth_code'];
        }

        if ($expiry = OpenproviderRegistrarDriver::parseExpiration($result['expiration_date'] ?? null)) {
            $updates['expires_at'] = $expiry;
        }

        $domain->update($updates);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function storeRemoteRegistrarId(array &$updates, mixed $externalId): void
    {
        if ($externalId === null || $externalId === '') {
            return;
        }

        if (is_numeric($externalId)) {
            $updates['registrar_external_id'] = (int) $externalId;

            return;
        }

        $updates['registrar_handle'] = (string) $externalId;
    }

    private function activateLinkedServices(ResellerDomainOrder $order): void
    {
        Service::query()
            ->where('user_id', $order->customer_id)
            ->where(function ($query) use ($order) {
                $query->whereJsonContains('service_meta->domain_id', $order->domain_id)
                    ->orWhere('name', $order->domain_name.$order->extension);
            })
            ->update(['status' => ServiceStatus::Provisioning->value]);
    }

    private function resolveRegistrar(Domain $domain): ?Registrar
    {
        $extension = $domain->domainExtension
            ?? DomainExtension::where('extension', $domain->extension)->first();

        if (! $extension) {
            return null;
        }

        return $this->registrarManager->forExtension($extension);
    }

    private function operationsDriver(?Registrar $registrar): ?RegistrarOperationsInterface
    {
        if (! $registrar || ! $registrar->is_active) {
            return null;
        }

        $driver = $this->registrarManager->driver($registrar);

        return $driver instanceof RegistrarOperationsInterface ? $driver : null;
    }

    public function usesOpenprovider(DomainExtension $extension): bool
    {
        $registrar = $this->registrarManager->forExtension($extension);

        return $registrar?->driver === RegistrarDriver::Openprovider && ($registrar?->is_active ?? false);
    }
}
