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
        $this->runOrderFulfillment($order, manual: false);
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

        if ($domain->registrar_external_id && in_array($domain->status, ['pending', 'active'], true)) {
            if ($manual) {
                return ['success' => false, 'message' => 'This domain already has an active registrar submission.'];
            }

            return ['success' => false, 'message' => ''];
        }

        $registrar = $this->resolveRegistrar($domain);
        $driver = $this->operationsDriver($registrar);

        if (! $driver) {
            if ($manual) {
                return ['success' => false, 'message' => 'No API registrar is configured for this TLD.'];
            }

            return ['success' => false, 'message' => ''];
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

            Log::error('Registrar fulfillment failed', $context);

            app(DomainPushService::class)->failOrder($order->fresh(), $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
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
                    'Renewed automatically via Openprovider.',
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

        if (! empty($result['external_id'])) {
            $updates['registrar_external_id'] = $result['external_id'];
        }

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
            app(DomainPushService::class)->failOrdersForDomain(
                $domain->fresh(),
                'Registrar reported registration failure.',
            );
        }

        return $status === 'ACT';
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
                app(DomainPushService::class)->failOrder($order, $message);
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

        if (! empty($result['external_id'])) {
            $updates['registrar_external_id'] = $result['external_id'];
        }

        if (! empty($result['auth_code'])) {
            $updates['epp_code'] = $result['auth_code'];
        }

        if ($expiry = OpenproviderRegistrarDriver::parseExpiration($result['expiration_date'] ?? null)) {
            $updates['expires_at'] = $expiry;
        }

        $domain->update($updates);
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
