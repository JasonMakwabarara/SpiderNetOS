<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Agent;
use App\Services\AIService;
use App\Services\AgentTaskService;
use App\Services\EmailService;
use Illuminate\Support\Facades\Log;

class AgentController extends Controller
{
    public function index() { return response()->json(Agent::all()); }
    
    public function store(Request $request) 
    { 
        try {
            $agent = Agent::create($request->all());
            return response()->json($agent, 201);
        } catch (\Exception $e) {
            Log::error('Agent creation failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    public function show($id) { return response()->json(Agent::findOrFail($id)); }
    public function update(Request $request, $id) { $agent = Agent::findOrFail($id); $agent->update($request->all()); return response()->json($agent); }
    public function destroy($id) { Agent::destroy($id); return response()->json(['message' => 'Deleted']); }

    public function chat(Request $request)
    {
        try {
            $agent = Agent::findOrFail($request->agent_id);
            $message = $request->message;
            $lower = strtolower($message);

            // CHECK FOR TASKS / COMMANDS
            // 1. Send Email
            if (preg_match('/send email to (.+?) about (.+)/i', $message, $matches)) {
                $to = trim($matches[1]);
                $subject = trim($matches[2]);
                $body = $request->input('body', 'Email sent by ' . $agent->name);
                
                $emailService = new EmailService();
                $result = $emailService->sendEmail($to, $subject, $body);
                
                if ($result['success']) {
                    return response()->json(['response' => "? Email sent to $to! (Subject: $subject)"]);
                } else {
                    return response()->json(['response' => "? Failed to send email: " . $result['message']]);
                }
            }

            // 2. Create Agent
            if (preg_match('/create agent (.+)/i', $message, $matches)) {
                $name = trim($matches[1]);
                $existing = Agent::where('name', $name)->first();
                if ($existing) {
                    return response()->json(['response' => "?? Agent '$name' already exists!"]);
                }
                $newAgent = Agent::create([
                    'name' => $name,
                    'slug' => \Illuminate\Support\Str::slug($name),
                    'description' => 'Created by ' . $agent->name,
                    'capabilities' => ['chat'],
                    'status' => 'active'
                ]);
                return response()->json(['response' => "? Agent '$name' created successfully!"]);
            }

            // 3. Create Flow
            if (preg_match('/create flow (.+)/i', $message, $matches)) {
                $name = trim($matches[1]);
                $flow = \App\Models\Flow::create([
                    'name' => $name,
                    'slug' => \Illuminate\Support\Str::slug($name),
                    'description' => 'Created by ' . $agent->name,
                    'dag' => ['steps' => []],
                    'triggers' => ['manual'],
                    'status' => 'draft'
                ]);
                return response()->json(['response' => "? Flow '$name' created successfully!"]);
            }

            // 4. List Agents
            if ($lower === '/agents' || $lower === '/agents') {
                $agents = Agent::all();
                if ($agents->isEmpty()) {
                    return response()->json(['response' => "?? No agents yet."]);
                }
                $list = "?? **Your Agents:**\n";
                foreach ($agents as $a) {
                    $list .= "? {$a->name} ({$a->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            // 5. List Flows
            if ($lower === '/flows' || $lower === '/flows') {
                $flows = \App\Models\Flow::all();
                if ($flows->isEmpty()) {
                    return response()->json(['response' => "?? No flows yet."]);
                }
                $list = "?? **Your Flows:**\n";
                foreach ($flows as $f) {
                    $list .= "? {$f->name} ({$f->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            // 6. Help
            if ($lower === '/help' || $lower === '/help') {
                return response()->json(['response' => 
                    "**Available Commands:**\n\n" .
                    "? `send email to [email] about [subject]` - Send an email\n" .
                    "? `create agent [name]` - Create a new AI agent\n" .
                    "? `create flow [name]` - Create a new workflow\n" .
                    "? `/agents` - List all agents\n" .
                    "? `/flows` - List all flows\n" .
                    "? `/help` - Show this help"
                ]);
            }

            // Default: Use AI for normal chat
            $ai = new AIService();
            $reply = $ai->chat($agent->name, $agent->description ?? 'AI assistant', $message);
            return response()->json(['response' => $reply]);
            
        } catch (\Exception $e) {
            Log::error('Agent chat failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function executeTask(Request $request)
    {
        try {
            $agent = Agent::findOrFail($request->agent_id);
            $taskService = new AgentTaskService();
            $task = $request->task;
            $params = $request->params ?? [];

            $result = null;
            switch ($task) {
                case 'send_email':
                    $result = $taskService->sendEmail(
                        $agent,
                        $params['to'] ?? '',
                        $params['subject'] ?? 'Task from ' . $agent->name,
                        $params['body'] ?? 'This is an automated task from ' . $agent->name
                    );
                    break;
                case 'send_slack':
                    $result = $taskService->sendSlack(
                        $params['webhook'] ?? '',
                        $params['message'] ?? 'Task from ' . $agent->name
                    );
                    break;
                case 'call_webhook':
                    $result = $taskService->callWebhook(
                        $params['url'] ?? '',
                        $params['data'] ?? []
                    );
                    break;
                default:
                    return response()->json(['error' => 'Unknown task: ' . $task], 400);
            }

            return response()->json(['task' => $task, 'result' => $result]);
        } catch (\Exception $e) {
            Log::error('Task execution failed: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
