<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Services\ResellerEmailTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function __construct(
        private ResellerEmailTemplateService $templates,
    ) {}

    public function update(Request $request, string $eventKey): JsonResponse
    {
        $reseller = $request->user();
        abort_unless($reseller?->isReseller(), 403);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|max:10000',
            'enabled' => 'sometimes|boolean',
        ]);

        try {
            $template = $this->templates->update($reseller, $eventKey, $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email template updated successfully.',
            'template' => $template,
        ]);
    }

    public function reset(Request $request, string $eventKey): JsonResponse
    {
        $reseller = $request->user();
        abort_unless($reseller?->isReseller(), 403);

        try {
            $template = $this->templates->reset($reseller, $eventKey);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template reset to default successfully.',
            'template' => $template,
        ]);
    }
}
