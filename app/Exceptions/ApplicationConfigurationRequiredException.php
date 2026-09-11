<?php

namespace App\Exceptions;

/**
 * The stack provisioned correctly but the customer's application refuses to
 * start until it is given values only the customer holds — third-party API
 * credentials and the like.
 *
 * This is deliberately not a deployment failure. Everything the platform owns
 * worked, so the deploy is held for configuration rather than rolled back.
 *
 * Two lists, because a customer fixes them differently. A missing name has no
 * value and needs one. An invalid name has a value that the application cannot
 * use, and telling somebody to add a setting they can already see in front of
 * them is how a support ticket starts.
 */
class ApplicationConfigurationRequiredException extends \RuntimeException
{
    /**
     * @param  list<string>  $missingVariables
     * @param  list<string>  $invalidVariables
     */
    public function __construct(
        private readonly array $missingVariables,
        string $message = '',
        private readonly array $invalidVariables = [],
    ) {
        parent::__construct(
            $message !== '' ? $message : self::describe($missingVariables, $invalidVariables)
        );
    }

    /**
     * @return list<string>
     */
    public function missingVariables(): array
    {
        return $this->missingVariables;
    }

    /**
     * Names the application rejected the value of. They are set, so nothing
     * that reports what is unset will ever mention them.
     *
     * @return list<string>
     */
    public function invalidVariables(): array
    {
        return $this->invalidVariables;
    }

    /**
     * Every name the customer has to act on, whichever way it is wrong. This is
     * what the site's setup notice lists.
     *
     * @return list<string>
     */
    public function variablesToFix(): array
    {
        return array_values(array_unique([...$this->missingVariables, ...$this->invalidVariables]));
    }

    /**
     * @param  list<string>  $missingVariables
     * @param  list<string>  $invalidVariables
     */
    public static function describe(array $missingVariables, array $invalidVariables = []): string
    {
        $sentences = [];

        if ($missingVariables !== []) {
            $sentences[] = 'The application needs these environment variables before it can start: '
                .implode(', ', $missingVariables).'.';
        }

        if ($invalidVariables !== []) {
            $sentences[] = 'The application cannot use the value set for '
                .implode(', ', $invalidVariables).'.';
        }

        if ($sentences === []) {
            return 'The application needs environment variables that have not been set yet.';
        }

        return implode(' ', $sentences);
    }
}
