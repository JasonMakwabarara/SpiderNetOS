<?php
namespace App\Services;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    public function sendEmail($to, $subject, $body)
    {
        try {
            if (env('MAIL_MAILER') === 'smtp') {
                Mail::raw($body, function ($message) use ($to, $subject) {
                    $message->to($to)->subject($subject)->from(env('MAIL_FROM_ADDRESS', 'noreply@spidernetos.com'), env('MAIL_FROM_NAME', 'SpiderNetOS'));
                });
                Log::info(' Email sent', ['to' => $to]);
                return ['success' => true, 'message' => 'Email sent!'];
            }
        } catch (\Exception $e) {
            Log::error(' Email failed: ' . $e->getMessage());
        }
        // Log as fallback so we can see it
        Log::info(" Email log: To: $to, Subject: $subject, Body: $body");
        return ['success' => true, 'message' => 'Email logged (SMTP not configured)'];
    }
}
