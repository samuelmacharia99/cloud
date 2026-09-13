<?php

namespace App\Services\Provisioning;

use DomainException;

/**
 * A per-container action the customer asked for cannot be done. The message
 * is written for the customer and is safe to flash back verbatim.
 */
final class StackMemberActionException extends DomainException
{
    public static function notDeployed(): self
    {
        return new self('This application is not deployed on a host yet.');
    }

    public static function terminated(): self
    {
        return new self('This application has been terminated.');
    }

    public static function nodeNotConfigured(): self
    {
        return new self('Container host is not properly configured (missing SSH credentials). Please contact support.');
    }

    public static function unknownMember(string $composeKey): self
    {
        return new self("There is no container named \"{$composeKey}\" in this stack.");
    }

    public static function notRestartable(StackMember $member): self
    {
        if ($member->synthesized) {
            return new self("The {$member->label} container has not been created yet.");
        }

        return new self("The {$member->label} container is part of the application. Use Restart on the stack to restart it.");
    }

    public static function busy(): self
    {
        return new self('Another action is still running on this stack. Wait a moment and try again.');
    }

    public static function stillNotRunning(StackMember $member, int $waitedSeconds): self
    {
        return new self("The {$member->label} container was restarted but is not running after {$waitedSeconds}s. Check the Logs tab or run Diagnose.");
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
