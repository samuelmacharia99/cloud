<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RestartStackMemberRequest;
use App\Models\CustomerProject;
use App\Models\Service;
use App\Services\Provisioning\ContainerStackMemberService;
use App\Services\Provisioning\StackMemberActionException;
use App\Services\Provisioning\StackMemberStateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Per-container actions on a stack (today: restart the database) and the
 * on-demand refresh of a project's container states. Kept out of
 * ContainerController on purpose; that file is already the size of a book.
 */
class ContainerStackMemberController extends Controller
{
    public function __construct(
        private ContainerStackMemberService $members,
        private StackMemberStateService $states,
    ) {}

    public function restart(RestartStackMemberRequest $request, Service $service, string $member): RedirectResponse
    {
        $this->authorize('manageContainer', $service);

        try {
            $result = $this->members->restart($service, $request->composeKey(), $request->user());
        } catch (StackMemberActionException $e) {
            return back()->withErrors(['error' => $e->userMessage()]);
        } catch (\Throwable $e) {
            Log::error("Failed to restart stack member for service {$service->id}", [
                'member' => $request->composeKey(),
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not restart that container. Try again or contact support.']);
        }

        return back()->with('success', "{$result->member->label} restarted.");
    }

    public function refreshProject(CustomerProject $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $summary = $this->states->refreshProject($project);

        if ($summary['refreshed'] === 0 && $summary['failed'] > 0) {
            return back()->withErrors(['error' => 'Could not reach the host to check container states. Try again shortly.']);
        }

        $message = 'Checked '.$summary['refreshed'].' '.($summary['refreshed'] === 1 ? 'stack' : 'stacks').'.';
        if ($summary['failed'] > 0) {
            $message .= ' '.$summary['failed'].' could not be reached.';
        }

        return back()->with('success', $message);
    }
}
