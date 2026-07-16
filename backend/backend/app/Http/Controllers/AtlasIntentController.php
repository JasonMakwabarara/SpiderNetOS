<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\Ticket;
use App\Models\CrmRecord;
use App\Services\EmailService;
use App\Services\AIService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class AtlasIntentController extends Controller
{
    public function handleIntent(Request $request)
    {
        try {
            $text = $request->input('message', '');
            $lower = strtolower($text);

            // SEND EMAIL
            if (preg_match('/send email to (.+?) about (.+)/i', $text, $matches)) {
                $to = trim($matches[1]);
                $subject = trim($matches[2]);
                $body = $request->input('body', 'Auto email from Atlas.');

                $email = new EmailService();
                $result = $email->sendEmail($to, $subject, $body);

                return response()->json([
                    'response' => " Email sent to $to! (Subject: $subject)",
                    'result' => $result
                ]);
            }

            // CREATE TICKET
            if (preg_match('/create ticket (.+?) for (.+)/i', $text, $matches)) {
                $title = trim($matches[1]);
                $description = trim($matches[2]);
                
                $ticket = Ticket::create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => '00000000-0000-0000-0000-000000000001',
                    'title' => $title,
                    'description' => $description,
                    'status' => 'open',
                    'priority' => 'medium'
                ]);
                
                return response()->json([
                    'response' => " Ticket '$title' created!\n Status: Open"
                ]);
            }

            // UPDATE CRM
            if (preg_match('/update crm (.+?) to (.+)/i', $text, $matches)) {
                $field = trim($matches[1]);
                $value = trim($matches[2]);
                
                $crm = CrmRecord::create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => '00000000-0000-0000-0000-000000000001',
                    'field' => $field,
                    'value' => $value
                ]);
                
                return response()->json([
                    'response' => " CRM updated: $field = $value"
                ]);
            }

            // LIST TICKETS
            if ($lower === '/tickets') {
                $tickets = Ticket::all();
                if ($tickets->isEmpty()) {
                    return response()->json(['response' => " No tickets yet."]);
                }
                $list = " **Your Tickets:**\n";
                foreach ($tickets as $t) {
                    $list .= " {$t->title} ({$t->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            // LIST CRM
            if ($lower === '/crm') {
                $crm = CrmRecord::all();
                if ($crm->isEmpty()) {
                    return response()->json(['response' => " No CRM records yet."]);
                }
                $list = " **CRM Records:**\n";
                foreach ($crm as $c) {
                    $list .= " {$c->field} = {$c->value}\n";
                }
                return response()->json(['response' => $list]);
            }

            // CREATE AGENT
            if (preg_match('/create agent (.+)/i', $text, $matches)) {
                $name = trim($matches[1]);
                $existing = Agent::where('name', $name)->first();
                if ($existing) {
                    return response()->json(['response' => " Agent '$name' already exists!"]);
                }
                $agent = Agent::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => 'Auto-created by Atlas',
                    'capabilities' => ['chat'],
                    'status' => 'active'
                ]);
                return response()->json(['response' => " Agent '$name' created!"]);
            }

            // CREATE FLOW
            if (preg_match('/create flow (.+)/i', $text, $matches)) {
                $name = trim($matches[1]);
                $flow = Flow::create([
                    'name' => $name,
                    'slug' => Str::slug($name),
                    'description' => 'Auto-created by Atlas',
                    'dag' => ['steps' => []],
                    'triggers' => ['manual'],
                    'status' => 'draft'
                ]);
                return response()->json(['response' => " Flow '$name' created!"]);
            }

            // LIST AGENTS
            if ($lower === '/agents') {
                $agents = Agent::all();
                if ($agents->isEmpty()) {
                    return response()->json(['response' => " No agents yet."]);
                }
                $list = " **Your Agents:**\n";
                foreach ($agents as $a) {
                    $list .= " {$a->name} ({$a->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            // LIST FLOWS
            if ($lower === '/flows') {
                $flows = Flow::all();
                if ($flows->isEmpty()) {
                    return response()->json(['response' => " No flows yet."]);
                }
                $list = " **Your Flows:**\n";
                foreach ($flows as $f) {
                    $list .= " {$f->name} ({$f->status})\n";
                }
                return response()->json(['response' => $list]);
            }

            // HELP
            if ($lower === '/help') {
                return response()->json(['response' => 
                    "** Available Commands:**\n\n" .
                    " `send email to [email] about [subject]`\n" .
                    " `create ticket [title] for [description]`\n" .
                    " `/tickets` - List tickets\n" .
                    " `update crm [field] to [value]`\n" .
                    " `/crm` - List CRM\n" .
                    " `create agent [name]`\n" .
                    " `/agents` - List agents\n" .
                    " `create flow [name]`\n" .
                    " `/flows` - List flows\n" .
                    " `/help` - Show this"
                ]);
            }

            // DEFAULT
            $ai = new AIService();
            $response = $ai->chat('Atlas', 'You are Atlas, an AI Operating System assistant.', $text);
            return response()->json(['response' => $response]);
            
        } catch (\Exception $e) {
            Log::error('AtlasIntent error: ' . $e->getMessage());
            return response()->json(['response' => ' Error: ' . $e->getMessage()], 500);
        }
    }
}
