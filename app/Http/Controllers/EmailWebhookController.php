<?php

namespace App\Http\Controllers;

use App\Services\EmailDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmailWebhookController extends Controller
{
    public function bounce(Request $request, EmailDeliveryService $emailDelivery)
    {
        $token = (string) (config('services.email_bounce.token') ?: env('EMAIL_BOUNCE_TOKEN', ''));

        if ($token === '') {
            Log::warning('Email bounce webhook rejected: token not configured');

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! hash_equals($token, (string) $request->header('X-Email-Bounce-Token', ''))) {
            Log::warning('Email bounce webhook rejected: invalid token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'message_id' => 'required|string|max:255',
            'reason' => 'nullable|string|max:1000',
        ]);

        $emailDelivery->markBounced($validated['message_id'], $validated['reason'] ?? null);

        Log::info('Email bounce recorded', ['message_id' => $validated['message_id']]);

        return response()->json(['success' => true]);
    }
}
