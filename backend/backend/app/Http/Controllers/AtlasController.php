<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Agent;
use App\Models\Flow;
use Illuminate\Support\Str;

class AtlasController extends Controller
{
    public function chat(Request $request)
    {
        try {
            $text = $request->input('message', '');
            $lower = strtolower($text);

            if (!str_contains($lower, 'create') && !str_contains($lower, '/') && !str_contains($lower, 'help')) {
                return response()->json([
                    'response' => "I'm here to help! Try these commands:\n\n? `create agent [name]`\n? `create flow [name]`\n? `create email flow [name] to [email]`\n? `create slack flow [name] webhook [url]`\n? `/agents`\n? `/flows`\n? `/help`"
                ]);
            }

            // CREATE AGENT
            if (str_contains($lower, 'create agent')) {
                $name = trim(preg_replace('/create agent/i', '', $text));
                if (empty($name)) {
                    return response()->json(['response' => 'Please specify an agent name.']);
                }
                if (Agent::where('name', $name)->exists()) {
                    return response()->json(['response' => "?? Agent \"{$name}\" already exists."]);
                }
                $agent = Agent::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => "Created via Atlas: {$name}",
                    'type' => 'custom',
                    'capabilities' => ['chat'],
                    'status' => 'active',
                    'tenant_id' => '00000000-0000-0000-0000-000000000001'
                ]);
                return response()->json([
                    'response' => "? Agent \"{$name}\" created successfully!\n\nYou can now:\n? Edit its role and capabilities on the Agents page\n? Chat with it to perform specific tasks"
                ]);
            }

            // CREATE EMAIL FLOW - FIXED
            if (str_contains($lower, 'create email flow')) {
                $match = preg_match('/create email flow (.+?) to (.+)/i', $text, $matches);
                if (!$match || count($matches) < 3) {
                    return response()->json(['response' => '? Please use: create email flow [name] to [email@example.com]']);
                }
                $name = trim($matches[1]);
                $email = trim($matches[2]);

                if (empty($name) || empty($email)) {
                    return response()->json(['response' => '? Please provide both name and email.']);
                }

                $flow = Flow::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => "Email flow: {$name}",
                    'dag' => [
                        'steps' => [
                            [
                                'type' => 'email',
                                'label' => 'Send Email',
                                'config' => [
                                    'to' => $email,
                                    'subject' => "Flow {$name} executed",
                                    'body' => "The flow \"{$name}\" was triggered successfully!"
                                ]
                            ]
                        ]
                    ],
                    'triggers' => ['manual'],
                    'status' => 'draft',
                    'tenant_id' => '00000000-0000-0000-0000-000000000001'
                ]);
                return response()->json([
                    'response' => "? Email flow \"{$name}\" created!\n\n?? Will send to: {$email}\n?? Go to Flows page and click Execute to test it."
                ]);
            }

            // CREATE SLACK FLOW
            if (str_contains($lower, 'create slack flow')) {
                $match = preg_match('/create slack flow (.+?) webhook (.+)/i', $text, $matches);
                if (!$match || count($matches) < 3) {
                    return response()->json(['response' => '? Please use: create slack flow [name] webhook [https://hooks.slack.com/...]']);
                }
                $name = trim($matches[1]);
                $webhook = trim($matches[2]);

                $flow = Flow::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => "Slack flow: {$name}",
                    'dag' => [
                        'steps' => [
                            [
                                'type' => 'slack',
                                'label' => 'Send Slack',
                                'config' => [
                                    'webhook' => $webhook,
                                    'message' => "The flow \"{$name}\" was executed!"
                                ]
                            ]
                        ]
                    ],
                    'triggers' => ['manual'],
                    'status' => 'draft',
                    'tenant_id' => '00000000-0000-0000-0000-000000000001'
                ]);
                return response()->json([
                    'response' => "? Slack flow \"{$name}\" created!\n\n?? Will send to your Slack channel.\n?? Go to Flows page and click Execute to test it."
                ]);
            }

            // CREATE FLOW (generic)
            if (str_contains($lower, 'create flow') && !str_contains($lower, 'email') && !str_contains($lower, 'slack')) {
                $name = trim(preg_replace('/create flow/i', '', $text));
                if (empty($name)) {
                    return response()->json(['response' => 'Please specify a flow name.']);
                }
                if (Flow::where('name', $name)->exists()) {
                    return response()->json(['response' => "?? Flow \"{$name}\" already exists."]);
                }
                $flow = Flow::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => "Created via Atlas: {$name}",
                    'dag' => ['steps' => []],
                    'triggers' => ['manual'],
                    'status' => 'draft',
                    'tenant_id' => '00000000-0000-0000-0000-000000000001'
                ]);
                return response()->json([
                    'response' => "? Flow \"{$name}\" created successfully!\n\nYou can now:\n? Add actions (Slack, Email, Webhook) from the Flows page\n? Click Execute to run it"
                ]);
            }

            if ($lower === '/agents') {
                $agents = Agent::all();
                if ($agents->count() === 0) {
                    return response()->json(['response' => 'No agents yet.']);
                }
                $list = "**?? Your Agents:**\n";
                foreach ($agents as $a) {
                    $list .= "? {$a->name} ({$a->type}) - {$a->status}\n";
                }
                return response()->json(['response' => $list]);
            }

            if ($lower === '/flows') {
                $flows = Flow::all();
                if ($flows->count() === 0) {
                    return response()->json(['response' => 'No flows yet.']);
                }
                $list = "**?? Your Flows:**\n";
                foreach ($flows as $f) {
                    $list .= "? {$f->name} ({$f->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            if ($lower === '/help') {
                return response()->json(['response' => "**?? Available Commands:**\n\n? `create agent [name]`\n? `create flow [name]`\n? `create email flow [name] to [email]`\n? `create slack flow [name] webhook [url]`\n? `/agents`\n? `/flows`\n? `/status`\n? `/help`"]);
            }

            if ($lower === '/status') {
                $agents = Agent::count();
                $flows = Flow::count();
                return response()->json([
                    'response' => "?? **System Status**\n? Agents: {$agents}\n? Flows: {$flows}\n? System: Online"
                ]);
            }

            return response()->json([
                'response' => "I'm here to help! Try '/help' to see available commands."
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'response' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
