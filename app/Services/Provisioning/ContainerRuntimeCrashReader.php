<?php

namespace App\Services\Provisioning;

/**
 * Turn a dead container's log into a cause somebody can act on.
 *
 * Python has a presenter that knows pydantic validation errors, Django's
 * ImproperlyConfigured, a bare environment KeyError and an unimportable module.
 * It was reachable from exactly one place in the codebase, inside the split
 * web/API readiness loop, so a single-container Python app never benefited from
 * any of it. This is the seam that lets every stack ask the same question.
 *
 * Ruby and Go have no presenter yet and fall back to the log summary, which
 * keeps the tail, where the exception is. That is honest: it reports what the
 * application said rather than inventing a diagnosis for a language nothing
 * here has learned to read.
 */
class ContainerRuntimeCrashReader
{
    /** @var list<string> */
    private const PRESENTED_STACKS = ['python'];

    public function __construct(
        private PythonRuntimeErrorPresenter $python,
        private ContainerApplicationRuntimeService $runtime,
    ) {}

    /**
     * @return array{message: string, missing_variables: list<string>, recognised: bool}
     */
    public function read(?string $stackSlug, string $logs): array
    {
        $logs = trim($logs);

        if ($logs === '') {
            return [
                // Deliberately not "check the logs". There are none, and sending
                // somebody to read an empty file wastes their afternoon.
                'message' => 'The application container stopped without writing anything to its log, '
                    .'which means it failed before your code ran. The usual cause is the start command '
                    .'not finding the file it expected.',
                'missing_variables' => [],
                'recognised' => false,
            ];
        }

        if (in_array((string) $stackSlug, self::PRESENTED_STACKS, true)) {
            $presented = $this->python->present($logs);
            if ($presented !== null) {
                return [
                    'message' => $presented['message'],
                    'missing_variables' => $presented['missing_variables'],
                    'recognised' => true,
                ];
            }
        }

        return [
            'message' => $this->runtime->summarizePythonContainerLogs($logs, 3000),
            'missing_variables' => [],
            'recognised' => false,
        ];
    }
}
