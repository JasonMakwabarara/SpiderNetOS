<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\DsarRequest;
use App\Services\AtlasDiscoveryService;
use App\Services\Compliance\DsarService;
use App\Services\ComplianceRadar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends Controller
{
    /**
     * POST /api/compliance/dsar { type, subject_type, subject_id?, subject_email? }
     * Creates and fulfils a data-subject export/erasure request (admin + step-up).
     */
    public function createDsar(Request $request, DsarService $service): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $v = $request->validate([
            'type' => 'required|in:export,erasure',
            'subject_type' => 'required|in:user,lead,email',
            'subject_id' => 'nullable|uuid',
            'subject_email' => 'nullable|email',
        ]);

        $req = DsarRequest::create([
            'tenant_id' => $tenantId,
            'type' => $v['type'],
            'subject_type' => $v['subject_type'],
            'subject_id' => $v['subject_id'] ?? null,
            'subject_email' => $v['subject_email'] ?? null,
            'requested_by' => $request->user()?->id,
            'status' => 'pending',
        ]);

        $service->fulfill($req);

        return response()->json(['data' => $req->only(['id', 'type', 'status', 'completed_at'])], 201);
    }

    /** GET /api/compliance/dsar/{id} — status + a download link for completed exports. */
    public function showDsar(Request $request, string $id): JsonResponse
    {
        $req = DsarRequest::forTenant($request->attributes->get('tenant_id'))->findOrFail($id);

        return response()->json(['data' => [
            'id' => $req->id,
            'type' => $req->type,
            'status' => $req->status,
            'completed_at' => $req->completed_at?->toIso8601String(),
            'download_url' => ($req->type === 'export' && $req->status === 'completed')
                ? url("/api/compliance/dsar/{$req->id}/download") : null,
        ]]);
    }

    /** GET /api/compliance/dsar/{id}/download — decrypt + stream the export bundle. */
    public function downloadDsar(Request $request, string $id)
    {
        $req = DsarRequest::forTenant($request->attributes->get('tenant_id'))->findOrFail($id);
        if ($req->type !== 'export' || ! $req->artifact_path || ! Storage::disk('local')->exists($req->artifact_path)) {
            abort(404);
        }

        $json = Crypt::decryptString(Storage::disk('local')->get($req->artifact_path));

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="dsar-'.$req->id.'.json"',
        ]);
    }

    /** GET /api/compliance/audit-log/export — admin audit log as CSV, with a stamp. */
    public function auditExport(Request $request): StreamedResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $rows = AdminAuditLog::where('tenant_id', $tenantId)->orderBy('created_at')->get();

        $callback = function () use ($rows) {
            $out = fopen('php://output', 'w');
            // Stamp row: the underlying event_log is hash-chained and separately
            // verifiable via `php artisan spidernet:verify-event-chain`.
            fputcsv($out, ['# SpiderNetOS admin audit export', now()->toIso8601String(), 'rows='.$rows->count(), 'chain=hash-chained (verify-event-chain)']);
            fputcsv($out, ['created_at', 'actor_id', 'actor_email', 'action', 'target_type', 'target_id', 'ip_address']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->created_at, $r->actor_id, $r->actor_email, $r->action, $r->target_type, $r->target_id, $r->ip_address]);
            }
            fclose($out);
        };

        return response()->streamDownload($callback, 'audit-log-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function obligations(Request $request, AtlasDiscoveryService $discovery, ComplianceRadar $radar): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $profile = $discovery->profileForTenant($tenantId);
        $obligations = $radar->obligationsForProfile($profile);

        return response()->json([
            'data' => $obligations,
            'profile_pct' => (int) ($profile['discovery_complete_pct'] ?? 0),
            'disclaimer' => 'Guidance only — not legal advice. Consult a qualified professional for regulatory decisions.',
        ]);
    }
}
