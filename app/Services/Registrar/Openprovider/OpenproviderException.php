<?php

namespace App\Services\Registrar\Openprovider;

use Exception;

class OpenproviderException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $apiCode = 0,
        public readonly ?array $response = null,
    ) {
        parent::__construct($message);
    }
}
