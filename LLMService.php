<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;
use Exception;

class LLMService
{
    protected $client;
    protected $maxRetries = 3;
    protected $timeout = 30;
    protected $costLimit = 0.50;

    public function __construct()
    {
        $this->client = OpenAI::client(env('OPENAI_API_KEY'));
    }

    public function chat(array $messages, string $model = 'gpt-3.5-turbo')
    {
        $retries = 0;
        $startTime = microtime(true);

        while ($retries < $this->maxRetries) {
            try {
                $response = $this->client->chat()->create([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.7,
                ]);

                $cost = $this->calculateCost($response->usage);
                if ($cost > $this->costLimit) {
                    throw new Exception("Cost limit exceeded: \${$cost}");
                }

                return $response->choices[0]->message->content;
            } catch (\Exception $e) {
                $retries++;
                Log::warning("LLM retry {$retries}: " . $e->getMessage());
                if ($retries >= $this->maxRetries) throw $e;
                sleep(1 * $retries);
            }
        }
    }

    private function calculateCost($usage): float
    {
        $inputCost = $usage->promptTokens * 0.0000015;
        $outputCost = $usage->completionTokens * 0.000002;
        return $inputCost + $outputCost;
    }
}
