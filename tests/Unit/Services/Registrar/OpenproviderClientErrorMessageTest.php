<?php

namespace Tests\Unit\Services\Registrar;

use App\Services\Registrar\Openprovider\OpenproviderClient;
use PHPUnit\Framework\TestCase;

class OpenproviderClientErrorMessageTest extends TestCase
{
    public function test_format_api_error_prefers_registry_message_for_code_399(): void
    {
        $message = OpenproviderClient::formatApiError(
            399,
            'An error has occurred; for more details, please refer to the registry message below.',
            ['registry_message' => 'Nameserver not found in the Openprovider system'],
        );

        $this->assertSame('Nameserver not found in the Openprovider system', $message);
    }

    public function test_format_api_error_appends_detail_to_specific_description(): void
    {
        $message = OpenproviderClient::formatApiError(
            226,
            'Invalid nameserver hostname',
            ['description' => 'Wrong hostname format'],
        );

        $this->assertSame('Invalid nameserver hostname Registry: Wrong hostname format', $message);
    }

    public function test_format_api_error_uses_contract_message_from_data(): void
    {
        $message = OpenproviderClient::formatApiError(
            309,
            'You have not signed the latest version of the contract for registering this domain',
            ['reason' => 'Sign .com contract in Openprovider control panel'],
        );

        $this->assertSame(
            'You have not signed the latest version of the contract for registering this domain Registry: Sign .com contract in Openprovider control panel',
            $message,
        );
    }

    public function test_format_operation_failure_reads_nested_results(): void
    {
        $message = OpenproviderClient::formatOperationFailure([
            'status' => 'FAI',
            'results' => [
                ['reason' => 'Domain already registered'],
            ],
        ]);

        $this->assertSame('Domain already registered', $message);
    }

    public function test_is_generic_error_description_detects_code_399_wrapper(): void
    {
        $this->assertTrue(OpenproviderClient::isGenericErrorDescription(
            'An error has occurred; for more details, please refer to the registry message below.',
            399,
        ));
        $this->assertFalse(OpenproviderClient::isGenericErrorDescription(
            'Invalid or double nameserver, nameserver must be unique!',
            222,
        ));
    }
}
