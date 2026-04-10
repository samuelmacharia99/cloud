<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Setting;

class ManualPaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize('update', Setting::class);
            return $next($request);
        });
    }

    /**
     * Show manual payment settings page
     */
    public function index()
    {
        $bankDetails = [
            'bank_name' => Setting::getValue('manual_bank_name', ''),
            'account_name' => Setting::getValue('manual_account_name', ''),
            'account_number' => Setting::getValue('manual_account_number', ''),
            'branch' => Setting::getValue('manual_bank_branch', ''),
            'swift_code' => Setting::getValue('manual_bank_swift', ''),
        ];

        return view('admin.manual-payment.index', compact('bankDetails'));
    }

    /**
     * Save manual payment bank details
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'bank_name' => 'required|string|max:100',
            'account_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'branch' => 'nullable|string|max:100',
            'swift_code' => 'nullable|string|max:20',
        ]);

        // Save each setting
        Setting::setValue('manual_bank_name', $validated['bank_name']);
        Setting::setValue('manual_account_name', $validated['account_name']);
        Setting::setValue('manual_account_number', $validated['account_number']);
        Setting::setValue('manual_bank_branch', $validated['branch'] ?? '');
        Setting::setValue('manual_bank_swift', $validated['swift_code'] ?? '');

        return redirect()->back()->with('success', 'Manual payment details saved successfully!');
    }
}
