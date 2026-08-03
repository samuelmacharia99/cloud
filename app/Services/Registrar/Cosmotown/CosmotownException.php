<?php

namespace App\Services\Registrar\Cosmotown;

use Exception;

class CosmotownException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $response = null,
    ) {
        parent::__construct($message);
    }
}
