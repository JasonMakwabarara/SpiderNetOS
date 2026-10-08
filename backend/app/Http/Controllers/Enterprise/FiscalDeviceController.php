<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Services\Enterprise\Fiscal\FdmsDeviceService;
use App\Services\Enterprise\Fiscal\FdmsSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FiscalDeviceController extends Controller
{
    public function __construct(private readonly FdmsDeviceService $fdms) {}

    public function status(Request $request): JsonResponse
    {
        $result = $this->fdms->status((string) $request->attributes->get('tenant_id'));

        return response()->json(['data' => $result['device'], 'remote' => $result['remote']]);
    }

    public function syncConfig(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fdms->syncConfig((string) $request->attributes->get('tenant_id'))]);
    }

    public function openDay(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fdms->openDay((string) $request->attributes->get('tenant_id'))]);
    }

    public function closeDay(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->fdms->closeDay((string) $request->attributes->get('tenant_id'))]);
    }

    public function fiscaliseInvoice(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'money_type' => ['required', 'string', Rule::in(FdmsSigner::MONEY_TYPES)],
        ]);
        $receipt = $this->fdms->fiscaliseInvoice(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['money_type'],
        );

        return response()->json(['data' => $receipt], 201);
    }

    public function fiscaliseCreditNote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'money_type' => ['required', 'string', Rule::in(FdmsSigner::MONEY_TYPES)],
        ]);
        $receipt = $this->fdms->fiscaliseCreditNote(
            (string) $request->attributes->get('tenant_id'),
            $id,
            $data['money_type'],
        );

        return response()->json(['data' => $receipt], 201);
    }
}
