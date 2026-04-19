<?php

namespace App\Http\Controllers\Admin;

use App\Models\SmsTemplate;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SmsTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('batchUpdate', Setting::class);
            return $next($request);
        });
    }

    public function update(Request $request, SmsTemplate $smsTemplate)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:320',
            'recipient_type' => 'required|in:customer,admin,both',
            'description' => 'nullable|string|max:255',
        ]);

        $smsTemplate->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'SMS template updated successfully.',
        ]);
    }

    public function reset(Request $request, SmsTemplate $smsTemplate)
    {
        $defaults = SmsTemplate::defaultTemplates();
        $default = collect($defaults)->firstWhere('event_key', $smsTemplate->event_key);

        if (!$default) {
            return response()->json([
                'success' => false,
                'message' => 'Default template not found.',
            ], 400);
        }

        $smsTemplate->update([
            'body' => $default['body'],
            'recipient_type' => $default['recipient_type'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Template reset to default successfully.',
        ]);
    }
}
