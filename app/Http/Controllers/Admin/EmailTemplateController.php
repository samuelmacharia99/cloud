<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Setting;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('batchUpdate', Setting::class);

            return $next($request);
        });
    }

    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
            'enabled' => 'sometimes|boolean',
        ]);

        $emailTemplate->update([
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'enabled' => array_key_exists('enabled', $validated) ? $validated['enabled'] : $emailTemplate->enabled,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully.',
        ]);
    }

    public function reset(Request $request, EmailTemplate $emailTemplate)
    {
        $default = collect(EmailTemplate::defaultTemplates())
            ->firstWhere('event_key', $emailTemplate->event_key);

        if (! $default) {
            return response()->json([
                'success' => false,
                'message' => 'Default template not found.',
            ], 400);
        }

        $emailTemplate->update([
            'subject' => $default['subject'],
            'body' => $default['body'],
            'recipient_type' => $default['recipient_type'],
            'enabled' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Template reset to default successfully.',
        ]);
    }
}
