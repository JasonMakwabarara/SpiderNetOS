<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRunStep;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\Exceptions\RunParkedException;
use App\Services\Inference\InferencePlaneClient;
use App\Services\Tools\ToolGateway;

/**
 * The model loop (plan D3). Single-shot: one completion, validated, one
 * colder retry when the JSON is malformed (RecruiterBot rule: formatting
 * misses are retried, content refusals never are). Agentic: a JSON
 * tool_calls protocol over the same /generate endpoint until the model
 * returns `final`, parking on waiting_approval when a tool needs a human
 * and resuming from `state.messages` (ResumeAgentRunJob).
 */
final class SkillLoop
{
    public const PROMPT_VERSION_FALLBACK = 'runtime-v1';

    private const REPAIR_SUFFIX = '

Your previous answer was not valid JSON. Reply with the JSON object only: no prose, no markdown fence, no trailing text.';

    private const AGENTIC_PROTOCOL = '

You may call tools. Reply with exactly one JSON object and nothing else:
  {"tool_calls":[{"name":"<tool name>","params":{...}}]}   — to use one or more tools (results come back as tool messages), or
  {"final":{...}}                                          — the finished output in the required shape.
Use only the tools listed. Never send or publish anything yourself; save drafts and submit them for review.';

    public function __construct(
        private readonly InferencePlaneClient $inference,
        private readonly ToolGateway $tools,
    ) {}

    // ------------------------------------------------------------------ //
    //  single_shot
    // ------------------------------------------------------------------ //

    /**
     * @return array{data: array<string, mixed>, raw: string, model: string, prompt_version: string, repaired: bool}
     */
    public function runSingleShot(RunContext $ctx): array
    {
        $built = $this->buildPrompt($ctx);
        $temperature = (float) ($ctx->card->model()['temperature'] ?? 0.3);

        $completion = $this->ask($ctx, $built['prompt'], $built['system'], $temperature);
        $verdict = $this->validate($ctx, $completion['text'], $built['facts']);
        $repaired = false;

        if (! $verdict['ok'] && self::isInvalidJson($verdict)) {
            $completion = $this->ask($ctx, $built['prompt'].self::REPAIR_SUFFIX, $built['system'], 0.0);
            $verdict = $this->validate($ctx, $completion['text'], $built['facts']);
            $repaired = $verdict['ok'];
        }

        if (! $verdict['ok']) {
            throw new AgentRuntimeException('validation_failed: '.implode('; ', $verdict['errors']), 'validation_failed', 422, ['errors' => $verdict['errors']]);
        }

        return [
            'data' => $verdict['data'],
            'raw' => (string) $completion['text'],
            'model' => (string) $completion['model'],
            'prompt_version' => $built['version'],
            'repaired' => $repaired || (bool) ($verdict['repaired'] ?? false),
        ];
    }

    // ------------------------------------------------------------------ //
    //  agentic (skeleton — PR 2 hardens it)
    // ------------------------------------------------------------------ //

    /**
     * @return array{data: array<string, mixed>, raw: string, model: string, prompt_version: string, repaired: bool}
     *
     * @throws RunParkedException when a tool call is waiting for a human
     */
    public function runAgentic(RunContext $ctx): array
    {
        $state = (array) ($ctx->run->state ?? []);
        $messages = (array) ($state['messages'] ?? []);
        $version = (string) ($state['prompt_version'] ?? '');

        if ($messages === []) {
            $built = $this->buildPrompt($ctx, ['mode' => 'agentic', 'tools' => $this->tools->catalogue()->jsonSchemas($ctx->allowlist)]);
            $version = $built['version'];
            $messages = [
                ['role' => 'system', 'content' => $built['system'].self::AGENTIC_PROTOCOL."\n\nTools:\n".json_encode($this->tools->catalogue()->jsonSchemas($ctx->allowlist), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
                ['role' => 'user', 'content' => $built['prompt']],
            ];
            $this->persist($ctx, $messages, 0, $version);
        }

        return $this->iterate($ctx, $messages, (int) ($state['iteration'] ?? 0), $version);
    }

    /** Continue an agentic run after its pending tool call was decided. */
    public function resumeAgentic(RunContext $ctx): array
    {
        $state = (array) ($ctx->run->state ?? []);
        $messages = (array) ($state['messages'] ?? []);
        $pending = (array) ($state['pending_tool_call'] ?? []);
        $version = (string) ($state['prompt_version'] ?? self::PROMPT_VERSION_FALLBACK);

        if ($pending !== []) {
            $granted = ($pending['decision'] ?? null) === 'approved';
            $result = $granted
                ? $this->tools->call($ctx, (string) $pending['tool'], (array) ($pending['params'] ?? []), ['approved' => true])
                : ['success' => false, 'error' => 'rejected_by_human', 'response' => (string) ($pending['response'] ?? '')];
            $messages[] = ['role' => 'tool', 'name' => (string) $pending['tool'], 'content' => json_encode($result, JSON_UNESCAPED_SLASHES)];
            $this->persist($ctx, $messages, (int) ($state['iteration'] ?? 0), $version, clearPending: true);
        }

        return $this->iterate($ctx, $messages, (int) ($state['iteration'] ?? 0), $version);
    }

    /** @param list<array<string, mixed>> $messages */
    private function iterate(RunContext $ctx, array $messages, int $iteration, string $version): array
    {
        $max = max(1, (int) ($ctx->card->limits()['max_iterations'] ?? 6));
        $maxToolCalls = max(1, (int) ($ctx->card->limits()['max_tool_calls'] ?? 20));
        $toolCalls = 0;
        $temperature = (float) ($ctx->card->model()['temperature'] ?? 0.2);

        while ($iteration < $max) {
            $iteration++;
            $completion = $this->ask($ctx, self::renderConversation($messages), self::systemOf($messages), $temperature);
            $text = (string) $completion['text'];
            $messages[] = ['role' => 'assistant', 'content' => $text];
            $parsed = self::decodeJson($text);

            if (is_array($parsed) && isset($parsed['tool_calls']) && is_array($parsed['tool_calls'])) {
                foreach ($parsed['tool_calls'] as $call) {
                    if (! is_array($call) || empty($call['name'])) {
                        continue;
                    }
                    if (++$toolCalls > $maxToolCalls) {
                        throw new AgentRuntimeException('max_tool_calls_exceeded', 'max_tool_calls_exceeded', 422);
                    }
                    $result = $this->tools->call($ctx, (string) $call['name'], (array) ($call['params'] ?? $call['arguments'] ?? []));
                    if (! empty($result['awaiting_approval'])) {
                        $this->persist($ctx, $messages, $iteration, $version);
                        throw new RunParkedException((string) ($result['approval_id'] ?? ''), (string) $call['name']);
                    }
                    $messages[] = ['role' => 'tool', 'name' => (string) $call['name'], 'content' => json_encode($result, JSON_UNESCAPED_SLASHES)];
                }
                $this->persist($ctx, $messages, $iteration, $version);

                continue;
            }

            $finalRaw = is_array($parsed) && array_key_exists('final', $parsed)
                ? (string) json_encode($parsed['final'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $text;
            $verdict = $this->validate($ctx, $finalRaw);
            if (! $verdict['ok']) {
                if ($iteration < $max) {
                    $messages[] = ['role' => 'user', 'content' => 'Your final output was rejected: '.implode('; ', $verdict['errors']).' Reply again with {"final":{...}} in the required shape.'];
                    $this->persist($ctx, $messages, $iteration, $version);

                    continue;
                }
                throw new AgentRuntimeException('validation_failed: '.implode('; ', $verdict['errors']), 'validation_failed', 422, ['errors' => $verdict['errors']]);
            }

            $this->persist($ctx, $messages, $iteration, $version);

            return [
                'data' => $verdict['data'],
                'raw' => $finalRaw,
                'model' => (string) $completion['model'],
                'prompt_version' => $version,
                'repaired' => (bool) ($verdict['repaired'] ?? false),
            ];
        }

        throw new AgentRuntimeException('max_iterations_exceeded', 'max_iterations_exceeded', 422, ['max_iterations' => $max]);
    }

    /** @param list<array<string, mixed>> $messages */
    private function persist(RunContext $ctx, array $messages, int $iteration, string $version, bool $clearPending = false): void
    {
        $state = (array) ($ctx->run->state ?? []);
        $state['messages'] = $messages;
        $state['iteration'] = $iteration;
        $state['prompt_version'] = $version;
        if ($clearPending) {
            unset($state['pending_tool_call']);
        }
        $ctx->run->forceFill(['state' => $state])->save();
    }

    // ------------------------------------------------------------------ //
    //  shared
    // ------------------------------------------------------------------ //

    /**
     * SkillPromptBuilder::build(SkillCard, BrainSnapshot, inputs, context).
     *
     * @return array{system: string, prompt: string, version: string}
     */
    private function buildPrompt(RunContext $ctx, array $context = []): array
    {
        $builder = Collaborators::promptBuilder();
        if ($builder === null) {
            throw new AgentRuntimeException('SkillPromptBuilder is not available in this build.', 'prompt_builder_missing', 503);
        }

        $context += [
            'run_id' => $ctx->run->id,
            'workspace' => $ctx->workspace?->slug,
            'autonomy_level' => $ctx->autonomy(),
            'trigger_type' => $ctx->run->trigger_type,
            'requested_by' => $ctx->requestedBy,
        ];

        $built = $builder->build($ctx->card->source(), $ctx->snapshot, $ctx->inputs(), $context);
        $built = is_object($built) ? get_object_vars($built) : (array) $built;

        $out = [
            'system' => (string) ($built['system'] ?? ''),
            'prompt' => (string) ($built['prompt'] ?? ''),
            'version' => (string) ($built['version'] ?? self::PROMPT_VERSION_FALLBACK),
            // SkillOutputValidator wants the facts the builder rendered.
            'facts' => is_array($built['facts'] ?? null) ? $built['facts'] : self::factsFor($ctx),
        ];

        $ctx->trace->step(AgentRunStep::KIND_PROMPT, $out['version'], [
            'system_chars' => mb_strlen($out['system']),
            'prompt_chars' => mb_strlen($out['prompt']),
            'brain_snapshot_hash' => ($ctx->run->state ?? [])['brain_snapshot_hash'] ?? null,
            'mode' => $context['mode'] ?? $ctx->card->mode(),
        ]);

        return $out;
    }

    /**
     * One /generate call with the card's model settings, charged to the run.
     *
     * @return array{text: string, model: string, tokens_used: int, cost: float, provider: string}
     */
    private function ask(RunContext $ctx, string $prompt, string $system, float $temperature): array
    {
        $model = $ctx->card->model();
        $maxTokens = max(64, (int) ($model['max_tokens'] ?? 1500));
        $heavy = (bool) ($model['heavy'] ?? false);
        $perRun = $ctx->card->perRunBudgetUsd() ?? (float) config('agents.per_run_budget_usd', 0.25);
        $ceiling = $perRun > 0 ? max(0.01, min(0.5, $perRun - $ctx->costSoFar())) : 0.5;
        $started = microtime(true);

        try {
            $completion = $this->inference->generate($prompt, $system, $ctx->tenantTier(), $ceiling, $maxTokens, $heavy, $temperature);
        } catch (\Throwable $e) {
            $ctx->trace->step(AgentRunStep::KIND_MODEL, $heavy ? 'heavy' : 'default', [
                'temperature' => $temperature, 'max_tokens' => $maxTokens,
            ], ['error' => $e->getMessage()], AgentRunStep::STATUS_FAILED, 0, 0.0, (int) ((microtime(true) - $started) * 1000));

            throw new AgentRuntimeException('model_error: '.$e->getMessage(), 'model_error', 502);
        }

        $tokens = (int) ($completion['tokens_used'] ?? 0);
        $cost = (float) ($completion['cost'] ?? 0.0);
        $ctx->budget->record($ctx, $cost, $tokens);
        $ctx->trace->step(AgentRunStep::KIND_MODEL, (string) ($completion['model'] ?? 'unknown'), [
            'temperature' => $temperature, 'max_tokens' => $maxTokens, 'cost_ceiling' => $ceiling,
        ], [
            'chars' => mb_strlen((string) $completion['text']),
            'provider' => $completion['provider'] ?? null,
        ], AgentRunStep::STATUS_OK, $tokens, $cost, (int) ((microtime(true) - $started) * 1000));

        return $completion;
    }

    /**
     * SkillOutputValidator::validate(SkillCard, raw, facts) normalised to an
     * array; without the validator (pre-merge builds) a JSON object is the
     * only requirement.
     *
     * @return array{ok: bool, data: array<string, mixed>, errors: list<string>, repaired: bool}
     */
    private function validate(RunContext $ctx, string $raw, ?array $facts = null): array
    {
        $validator = Collaborators::outputValidator();
        $facts ??= $this->factsFromBuilder($ctx);

        if ($validator === null) {
            $decoded = self::decodeJson($raw);
            $ok = is_array($decoded);
            $verdict = ['ok' => $ok, 'data' => $ok ? $decoded : [], 'errors' => $ok ? [] : ['invalid_json'], 'repaired' => false];
        } else {
            $result = $validator->validate($ctx->card->source(), $raw, $facts);
            $errors = [];
            if (is_object($result) && method_exists($result, 'codes')) {
                // ValidationResult: errors are {code, path?, message}; keep "code: message" strings.
                foreach ((array) ($result->errors ?? []) as $error) {
                    $errors[] = is_array($error)
                        ? trim((string) ($error['code'] ?? 'error').(isset($error['path']) ? ' @'.$error['path'] : '').': '.(string) ($error['message'] ?? ''))
                        : (string) $error;
                }
            }
            $result = is_object($result) ? get_object_vars($result) : (array) $result;
            $data = $result['data'] ?? [];
            $verdict = [
                'ok' => (bool) ($result['ok'] ?? false),
                'data' => is_array($data) ? $data : (is_object($data) ? get_object_vars($data) : []),
                'errors' => $errors !== [] ? $errors : array_values(array_map(static fn ($e) => is_string($e) ? $e : (string) json_encode($e), (array) ($result['errors'] ?? []))),
                'repaired' => (bool) ($result['repaired'] ?? false),
            ];
        }

        $ctx->trace->step(AgentRunStep::KIND_VALIDATOR, $validator !== null ? 'skill_output_validator' : 'json_only', [
            'chars' => mb_strlen($raw),
        ], ['ok' => $verdict['ok'], 'errors' => $verdict['errors'], 'repaired' => $verdict['repaired']], $verdict['ok'] ? AgentRunStep::STATUS_OK : AgentRunStep::STATUS_FAILED);

        return $verdict;
    }

    /**
     * SkillPromptBuilder::facts() when the builder exposes it (agentic
     * turns have no fresh build result), else the frontmatter fallback.
     *
     * @return array<string, mixed>
     */
    private function factsFromBuilder(RunContext $ctx): array
    {
        $builder = Collaborators::promptBuilder();
        if ($builder !== null && $ctx->snapshot !== null && method_exists($builder, 'facts')) {
            try {
                $facts = $builder->facts($ctx->card->source(), $ctx->snapshot);
                if (is_array($facts)) {
                    return $facts;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        return self::factsFor($ctx);
    }

    /**
     * Facts the validator may check the output against (links, figures)
     * from the snapshot's frontmatter of the card's required files.
     *
     * @return array<string, mixed>
     */
    private static function factsFor(RunContext $ctx): array
    {
        $facts = [];
        foreach ($ctx->card->readPaths() as $path) {
            $file = Collaborators::readBrainPath($ctx->tenantId, $path, $ctx->snapshot);
            if ($file !== null && $file['frontmatter'] !== []) {
                $facts[$path] = $file['frontmatter'];
            }
        }

        return $facts;
    }

    private static function isInvalidJson(array $verdict): bool
    {
        foreach ($verdict['errors'] as $error) {
            $error = strtolower((string) $error);
            if (str_contains($error, 'invalid_json') || str_contains($error, 'not valid json') || str_contains($error, 'malformed json') || str_contains($error, 'json_parse')) {
                return true;
            }
        }

        return false;
    }

    /** Lenient JSON: strips ```json fences and leading/trailing prose around the outermost object. */
    public static function decodeJson(string $text): mixed
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $messages */
    private static function systemOf(array $messages): string
    {
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'system') {
                return (string) ($m['content'] ?? '');
            }
        }

        return '';
    }

    /**
     * Render the non-system turns as one prompt for /generate (no native
     * chat/tools on the plane until PR 8).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    private static function renderConversation(array $messages): string
    {
        $lines = [];
        foreach ($messages as $m) {
            $role = (string) ($m['role'] ?? 'user');
            if ($role === 'system') {
                continue;
            }
            $label = match ($role) {
                'assistant' => 'ASSISTANT',
                'tool' => 'TOOL RESULT ('.(string) ($m['name'] ?? 'tool').')',
                default => 'USER',
            };
            $lines[] = "[{$label}]\n".(string) ($m['content'] ?? '');
        }
        $lines[] = '[ASSISTANT]';

        return implode("\n\n", $lines);
    }
}
