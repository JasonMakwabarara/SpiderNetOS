<?php

namespace App\Http\Controllers\Spend;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractSpendDocumentJob;
use App\Models\ExpenseItem;
use App\Models\SpendDocument;
use App\Services\EventStore;
use App\Services\Spend\CategorySuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpendDocumentController extends Controller
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly CategorySuggestionService $categorySuggestions,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $document = SpendDocument::forTenant($tenant->id)->findOrFail($id);

        $data = $document->toArray();
        $data['extraction'] = $document->extraction ?? null;

        return response()->json(['data' => $data]);
    }

    /**
     * User confirms (possibly corrected) extraction fields. Emits
     * spend_document.confirmed, which SpendAutomationProjection uses to
     * learn the merchant -> category mapping.
     */
    public function confirm(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'merchant' => 'required|string|max:255',
            'date' => 'required|date',
            'total' => 'required|numeric|min:0',
            'tax' => 'sometimes|nullable|numeric|min:0',
            'currency' => 'required|string|max:10',
            'category' => 'required|string|max:50',
            'chart_account_code' => 'sometimes|nullable|string|max:20',
            'line_items' => 'sometimes|array',
            'expense_item_id' => 'sometimes|nullable|uuid',
        ]);

        try {
            $document = DB::transaction(function () use ($tenant, $request, $id, $validated) {
                $document = SpendDocument::forTenant($tenant->id)->findOrFail($id);

                if ($document->status === 'confirmed') {
                    throw new \LogicException('Document is already confirmed.');
                }

                $update = [
                    'status' => 'confirmed',
                    'confirmed_by' => $request->user()->id,
                    'confirmed_at' => now(),
                ];

                if (! empty($validated['expense_item_id'])) {
                    $item = ExpenseItem::forTenant($tenant->id)->findOrFail($validated['expense_item_id']);
                    $update['attachable_type'] = ExpenseItem::class;
                    $update['attachable_id'] = $item->id;
                    $item->update(['has_receipt' => true]);
                }

                $document->update($update);

                $finalFields = [
                    'merchant' => $validated['merchant'],
                    'date' => $validated['date'],
                    'total' => (string) $validated['total'],
                    'tax' => isset($validated['tax']) ? (string) $validated['tax'] : null,
                    'currency' => $validated['currency'],
                    'category' => $validated['category'],
                    'chart_account_code' => $validated['chart_account_code'] ?? null,
                    'line_items' => $validated['line_items'] ?? [],
                ];

                $this->eventStore->append(
                    $tenant->id,
                    'spend_document',
                    $document->id,
                    'spend_document.confirmed',
                    [
                        'merchant' => $validated['merchant'],
                        'category' => $validated['category'],
                        'chart_account_code' => $validated['chart_account_code'] ?? null,
                        'final_fields' => $finalFields,
                        'extracted_fields' => $document->extraction['fields'] ?? null,
                        'extraction_method' => $document->extraction_method,
                    ]
                );

                return $document->fresh();
            });
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $document]);
    }

    /** Re-queue extraction for a failed document. */
    public function retry(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $document = SpendDocument::forTenant($tenant->id)->findOrFail($id);

        if ($document->status !== 'failed') {
            return response()->json([
                'error' => "Only failed documents can be retried. Current status: {$document->status}",
            ], 409);
        }

        $document->update(['status' => 'queued', 'error' => null]);

        ExtractSpendDocumentJob::dispatch($document->id);

        return response()->json(['data' => $document->fresh()]);
    }

    /** Ad-hoc category suggestion (no document required). */
    public function categorize(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'merchant' => 'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'amount' => 'sometimes|nullable|numeric|min:0',
        ]);

        $suggestion = $this->categorySuggestions->suggest(
            $tenant->id,
            $validated['merchant'],
            $validated['description'] ?? '',
            $validated['amount'] ?? null,
        );

        return response()->json(['data' => $suggestion]);
    }
}
