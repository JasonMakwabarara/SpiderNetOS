<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function chat($agentName, $systemPrompt, $message)
    {
        try {
            // Try DeepSeek API first
            $apiKey = env('DEEPSEEK_API_KEY');
            if ($apiKey) {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json'
                ])->timeout(30)->post('https://api.deepseek.com/v1/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => [
                        ['role' => 'system', 'content' => "You are $agentName. $systemPrompt"],
                        ['role' => 'user', 'content' => $message]
                    ],
                    'temperature' => 0.7,
                    'max_tokens' => 500
                ]);
                
                if ($response->successful()) {
                    $content = $response->json()['choices'][0]['message']['content'] ?? '';
                    if (!empty($content)) {
                        return $content;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('DeepSeek API error: ' . $e->getMessage());
        }
        
        // Fallback: Intelligent responses
        return $this->getFallbackResponse($agentName, $message);
    }
    
    private function getFallbackResponse($agentName, $message)
    {
        $lower = strtolower($message);
        
        if (str_contains($lower, '/agents')) {
            return "?? **Your Agents:**\n? SalesBot (active)\n? SupportBot (active)\n? TestBot (active)\n? Saleswer (active)";
        }
        if (str_contains($lower, '/flows')) {
            return "?? **Your Flows:**\n? DataP (draft)\n? TestFlow (draft)";
        }
        if (str_contains($lower, '/help')) {
            return "**Available Commands:**\n? `create agent [name]` - Create a new agent\n? `send email to [email] about [subject]` - Send an email\n? `create flow [name]` - Create a new flow\n? `/agents` - List all agents\n? `/flows` - List all flows\n? `/help` - Show this help";
        }
        if (str_contains($lower, 'create agent')) {
            return "I'll help you create a new agent. Please specify the agent name.\nExample: `create agent SalesBot`";
        }
        if (str_contains($lower, 'send email')) {
            return "I'll help you send an email. Please specify the recipient and subject.\nExample: `send email to test@example.com about Hello`";
        }
        
        return "Hello! I'm $agentName. How can I help you today? Try `/help` to see available commands.";
    }
}
