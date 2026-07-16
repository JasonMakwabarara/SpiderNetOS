<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentTaskService
{
    public function sendEmail($agent, $to, $subject, $body)
    {
        $emailService = new EmailService();
        return $emailService->sendEmail($to, $subject, $body);
    }
    public function sendSlack($webhook, $message)
    {
        try {
            $response = Http::post($webhook, ['text' => $message]);
            return ['success' => $response->successful(), 'message' => $response->successful() ? 'Slack sent' : 'Slack failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    public function callWebhook($url, $data)
    {
        try {
            $response = Http::post($url, $data);
            return ['success' => $response->successful(), 'message' => $response->successful() ? 'Webhook called' : 'Webhook failed'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
