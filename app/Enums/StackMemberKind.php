<?php

namespace App\Enums;

/**
 * What a container inside a customer's stack is for. Drives the label, the
 * icon and which per-container actions a customer may take: only databases
 * and caches are restarted on their own, the application containers are
 * restarted with the stack.
 */
enum StackMemberKind: string
{
    case App = 'app';
    case Backend = 'backend';
    case Frontend = 'frontend';
    case Edge = 'edge';
    case Database = 'database';
    case Cache = 'cache';
    case Worker = 'worker';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::App => 'App',
            self::Backend => 'Backend',
            self::Frontend => 'Frontend',
            self::Edge => 'Edge',
            self::Database => 'Database',
            self::Cache => 'Cache',
            self::Worker => 'Worker',
            self::Other => 'Container',
        };
    }

    public function isApplication(): bool
    {
        return in_array($this, [self::App, self::Backend, self::Frontend, self::Edge], true);
    }

    public function isIndividuallyRestartable(): bool
    {
        return in_array($this, [self::Database, self::Cache], true);
    }

    public function icon(): string
    {
        return match ($this) {
            self::App, self::Backend => 'server',
            self::Frontend => 'window',
            self::Edge => 'globe',
            self::Database => 'database',
            self::Cache => 'bolt',
            self::Worker => 'cog',
            self::Other => 'cube',
        };
    }
}
