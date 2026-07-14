<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Flow;
use App\Services\EmailService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlowController extends Controller
{
    public function index()
    {
        return response()->json(Flow::all());
    }

    public function store(Request $request)
    {
        try {
            $flow = Flow::create($request->all());
            return response()->json($flow, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        return response()->json(Flow::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $flow = Flow::findOrFail($id);
        $flow->update($request->all());
        return response()->json($flow);
    }

    public function destroy($id)
    {
        Flow::destroy($id);
        return response()->json(['message' => 'Deleted']);
    }

    public function execute($id)
    {
        try {
            $flow = Flow::findOrFail($id);
            $results = [];
            $steps = $flow->dag['steps'] ?? [];

            if (empty($steps)) {
                return response()->json(['message' => 'No steps to execute', 'status' => 'no_steps']);
            }

            foreach ($steps as $step) {
                $type = $step['type'] ?? 'unknown';
                $config = $step['config'] ?? [];

                if ($type === 'email') {
                    $email = new EmailService();
                    $result = $email->sendEmail(
                        $config['to'] ?? '',
                        $config['subject'] ?? 'Flow: ' . $flow->name,
                        $config['body'] ?? 'Flow executed: ' . $flow->name
                    );
                    $results[] = ['type' => 'email', 'result' => $result];
                    Log::info('Flow email sent', ['flow' => $flow->name]);
                }
                elseif ($type === 'slack') {
                    try {
                        $webhook = $config['webhook'] ?? '';
                        $message = $config['message'] ?? 'Flow executed: ' . $flow->name;
                        if (!empty($webhook)) {
                            $response = Http::post($webhook, ['text' => $message]);
                            $results[] = ['type' => 'slack', 'result' => ['success' => $response->successful()]];
                        } else {
                            $results[] = ['type' => 'slack', 'result' => ['success' => false, 'error' => 'No webhook URL provided']];
                        }
                    } catch (\Exception $e) {
                        $results[] = ['type' => 'slack', 'result' => ['success' => false, 'error' => $e->getMessage()]];
                    }
                }
                elseif ($type === 'webhook') {
                    try {
                        $url = $config['url'] ?? '';
                        $data = json_decode($config['data'] ?? '{}', true) ?? [];
                        if (!empty($url)) {
                            $response = Http::post($url, $data);
                            $results[] = ['type' => 'webhook', 'result' => ['success' => $response->successful()]];
                        } else {
                            $results[] = ['type' => 'webhook', 'result' => ['success' => false, 'error' => 'No URL provided']];
                        }
                    } catch (\Exception $e) {
                        $results[] = ['type' => 'webhook', 'result' => ['success' => false, 'error' => $e->getMessage()]];
                    }
                }
                else {
                    $results[] = ['type' => $type, 'result' => ['success' => false, 'error' => 'Unknown step type: ' . $type]];
                }
            }

            // Increment execution count
            $flow->increment('executions');

            return response()->json([
                'message' => 'Flow executed successfully',
                'status' => 'completed',
                'flow_id' => $id,
                'results' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Flow execution failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function publish($id)
    {
        $flow = Flow::findOrFail($id);
        $flow->status = 'published';
        $flow->published_at = now();
        $flow->save();
        return response()->json(['message' => 'Flow published', 'flow' => $flow]);
    }
}
