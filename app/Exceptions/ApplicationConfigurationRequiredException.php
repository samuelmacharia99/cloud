<?php

namespace App\Exceptions;

/**
 * The stack provisioned correctly but the customer's application refuses to
 * start until it is given values only the customer holds — third-party API
 * credentials and the like.
 *
 * This is deliberately not a deployment failure. Everything the platform owns
 * worked, so the deploy is held for configuration rather than rolled back.
 */
class ApplicationConfigurationRequiredException extends \RuntimeException
{
    /**
     * @param  list<string>  $missingVariables
     */
    public function __construct(
        private readonly array $missingVariables,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : self::describe($missingVariables));
    }

    /**
     * @return list<string>
     */
    public function missingVariables(): array
    {
        return $this->missingVariables;
    }

    /**
     * @param  list<string>  $missingVariables
     */
    public static function describe(array $missingVariables): string
    {
        if ($missingVariables === []) {
            return 'The application needs environment variables that have not been set yet.';
        }

        return 'The application needs these environment variables before it can start: '
            .implode(', ', $missingVariables).'.';
    }
}
