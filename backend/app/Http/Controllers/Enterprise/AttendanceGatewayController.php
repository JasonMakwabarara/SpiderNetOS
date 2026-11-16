<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceSite;
use App\Services\Enterprise\AttendanceHoursService;
use App\Services\Enterprise\AttendanceSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AttendanceGatewayController extends Controller
{
    public function __construct(
        private readonly AttendanceSyncService $sync,
        private readonly AttendanceHoursService $hours,
    ) {}

    public function indexSites(Request $request): JsonResponse
    {
        $sites = AttendanceSite::forTenant((string) $request->attributes->get('tenant_id'))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $sites]);
    }

    public function storeSite(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $secret = Str::random(40);
        $site = $this->sync->createSite((string) $request->attributes->get('tenant_id'), $data['name'], $secret);

        return response()->json(['data' => $site, 'secret' => $secret], 201);
    }

    public function revokeDevice(Request $request, string $id): JsonResponse
    {
        $device = $this->sync->revokeDevice((string) $request->attributes->get('tenant_id'), $id);

        return response()->json(['data' => $device]);
    }

    public function devices(Request $request): JsonResponse
    {
        $rows = AttendanceDevice::forTenant((string) $request->attributes->get('tenant_id'))
            ->with('employee')
            ->orderBy('external_device_id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ]);
        $from = $data['from'] ?? now()->toDateString();
        $to = $data['to'] ?? $from;

        return response()->json([
            'data' => $this->hours->report((string) $request->attributes->get('tenant_id'), $from, $to),
        ]);
    }

    public function syncBatch(Request $request): JsonResponse
    {
        $site = AttendanceSite::query()->find($request->header('X-Jetson-Site'));
        $secret = (string) $request->header('X-Jetson-Secret', '');
        $known = $site && $secret !== '' && hash_equals((string) $site->secret_hash, hash('sha256', $secret));
        if (! $known) {
            return response()->json(['message' => 'Unknown attendance site.'], 401);
        }

        $data = $request->validate([
            'cursor' => 'nullable|date',
            'caught_up' => 'sometimes|boolean',
            'people' => 'sometimes|array|max:200',
            'people.*.jetson_person_id' => 'required|string|max:64',
            'people.*.first_name' => 'required|string|max:100',
            'people.*.surname' => 'required|string|max:100',
            'people.*.employee_number' => 'nullable|string|max:50',
            'devices' => 'sometimes|array|max:200',
            'devices.*.external_device_id' => 'required|string|max:128',
            'devices.*.jetson_person_id' => 'required|string|max:64',
            'clock_events' => 'sometimes|array|max:200',
            'clock_events.*.external_event_id' => 'required|string|max:64',
            'clock_events.*.external_device_id' => 'required|string|max:128',
            'clock_events.*.type' => 'required|in:in,out',
            'clock_events.*.recorded_at' => 'required|date',
            'clock_events.*.sensor_id' => 'nullable|string|max:64',
        ]);

        return response()->json(['data' => $this->sync->sync($site, $data)]);
    }
}
