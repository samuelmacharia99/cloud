<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\ManualPaymentSetting;

class ManualPaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (!auth()->user()->is_admin) {
                abort(403);
            }
            return $next($request);
        });
    }

    /**
     * Show manual payment settings page
     */
    public function index()
    {
        $settings = ManualPaymentSetting::getCurrent();
        $bankDetails = [
            'bank_name' => $settings->bank_name,
            'account_name' => $settings->account_name,
            'account_number' => $settings->account_number,
            'branch' => $settings->branch,
            'swift_code' => $settings->swift_code,
        ];

        \Log::info('Manual payment settings viewed', [
            'admin_id' => auth()->id(),
            'admin_name' => auth()->user()->name,
        ]);

        return view('admin.manual-payment.index', compact('bankDetails'));
    }

    /**
     * Save manual payment bank details
     */
    public function update(Request $request)
    {
        \Log::info('Manual payment update request received', [
            'request_data' => $request->all(),
            'admin_id' => auth()->id(),
        ]);

        try {
            $validated = $request->validate([
                'bank_name' => 'required|string|max:100',
                'account_name' => 'required|string|max:100',
                'account_number' => 'required|string|max:50',
                'branch' => 'nullable|string|max:100',
                'swift_code' => 'nullable|string|max:20',
            ]);

            \Log::info('Manual payment validation passed', [
                'validated_data' => $validated,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Manual payment validation failed', [
                'errors' => $e->errors(),
                'admin_id' => auth()->id(),
            ]);
            throw $e;
        }

        // Get current settings for comparison
        $settings = ManualPaymentSetting::getCurrent();
        $oldValues = [
            'bank_name' => $settings->bank_name,
            'account_name' => $settings->account_name,
            'account_number' => $settings->account_number,
            'branch' => $settings->branch,
            'swift_code' => $settings->swift_code,
        ];

        \Log::info('Current settings before update', [
            'settings_id' => $settings->id,
            'old_values' => $oldValues,
            'new_values' => [
                'bank_name' => $validated['bank_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
                'branch' => $validated['branch'] ?? '',
                'swift_code' => $validated['swift_code'] ?? '',
            ],
        ]);

        // Update settings
        $updateResult = $settings->update([
            'bank_name' => $validated['bank_name'],
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
            'branch' => $validated['branch'] ?? '',
            'swift_code' => $validated['swift_code'] ?? '',
        ]);

        \Log::info('Settings update result', [
            'update_successful' => $updateResult,
            'updated_settings' => $settings->fresh()->toArray(),
        ]);

        // Log the changes with detailed information
        $changes = [];
        if ($oldValues['bank_name'] !== $validated['bank_name']) {
            $changes['bank_name'] = [
                'old' => $oldValues['bank_name'],
                'new' => $validated['bank_name'],
            ];
        }
        if ($oldValues['account_name'] !== $validated['account_name']) {
            $changes['account_name'] = [
                'old' => $oldValues['account_name'],
                'new' => $validated['account_name'],
            ];
        }
        if ($oldValues['account_number'] !== $validated['account_number']) {
            $changes['account_number'] = [
                'old' => $oldValues['account_number'],
                'new' => $validated['account_number'],
            ];
        }
        if ($oldValues['branch'] !== ($validated['branch'] ?? '')) {
            $changes['branch'] = [
                'old' => $oldValues['branch'],
                'new' => $validated['branch'] ?? '',
            ];
        }
        if ($oldValues['swift_code'] !== ($validated['swift_code'] ?? '')) {
            $changes['swift_code'] = [
                'old' => $oldValues['swift_code'],
                'new' => $validated['swift_code'] ?? '',
            ];
        }

        \Log::info('Manual payment details updated', [
            'admin_id' => auth()->id(),
            'admin_name' => auth()->user()->name,
            'changes' => $changes,
            'updated_fields_count' => count($changes),
            'ip_address' => $request->ip(),
        ]);

        return redirect()->back()->with('success', 'Manual payment details saved successfully!');
    }
}
