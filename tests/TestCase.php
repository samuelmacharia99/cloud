<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validRegistrantPayload(?User $user = null): array
    {
        return [
            'first_name' => 'Amina',
            'last_name' => 'Otieno',
            'email' => $user?->email ?? 'registrant@example.com',
            'phone' => '+254700000001',
            'company' => $user?->company,
            'address1' => '1 Kenyatta Avenue',
            'city' => 'Nairobi',
            'state' => 'Nairobi',
            'postal_code' => '00100',
            'country' => 'KE',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withRegistrant(array $payload, ?User $user = null): array
    {
        $payload['registrant'] = $this->validRegistrantPayload($user);

        return $payload;
    }
}
