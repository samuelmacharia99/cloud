<?php

namespace App\Services\Registrar;

use App\Models\Domain;
use App\Models\Registrar;

interface RegistrarOperationsInterface extends RegistrarDriverInterface
{
    /**
     * @return array{available: bool, status: ?string, price: ?array, premium: ?array, source: string}
     */
    public function checkAvailability(Registrar $registrar, string $name, string $extension, bool $withPrice = false): array;

    /**
     * @param  list<array{name: string}>  $nameServers
     * @return array{success: bool, status: string, external_id: ?int, auth_code: ?string, expiration_date: ?string, message: string}
     */
    public function registerDomain(Registrar $registrar, Domain $domain, int $years, array $nameServers): array;

    /**
     * @param  list<array{name: string}>  $nameServers
     * @return array{success: bool, status: string, external_id: ?int, expiration_date: ?string, message: string}
     */
    public function transferDomain(Registrar $registrar, Domain $domain, string $authCode, array $nameServers): array;

    /**
     * @return array{success: bool, status: string, expiration_date: ?string, message: string}
     */
    public function renewDomain(Registrar $registrar, Domain $domain, int $years): array;

    /**
     * @return array{success: bool, status: string, expiration_date: ?string, message: string}
     */
    public function syncDomainStatus(Registrar $registrar, Domain $domain): array;
}
