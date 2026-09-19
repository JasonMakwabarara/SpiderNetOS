<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * PromptEnhancer
 *
 * Turns a terse user prompt into a precise, structured instruction for the
 * downstream execution surface. Two modes are available:
 *   - "inference" — delegates to the inference plane's /generate endpoint
 *     (best-quality output, requires services.inference_url).
 *   - "deterministic" — pure-PHP fallback that applies the documented
 *     template ( §Objective / §Context / §Inputs / §Tasks / §Output / §Eval ).
 *
 * Both modes are safe to run in-request; the deterministic fallback
 * guarantees a response even when inference is unreachable.
 *
 * Context hints the button passes:
 *   surface  : "atlas_chat" | "agent_builder" | "flow_builder" | "generic"
 *   audience : "user" | "admin" | "super_admin"
 *   tone     : "neutral" | "concise" | "deep"
 */
class PromptEnhancer
{
    public function enhance(string $input, array $options = []): array
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return [
                'original' => $input,
                'enhanced' => $input,
                'mode' => 'noop',
                'notes' => ['Empty input; nothing to enhance.'],
            ];
        }

        $mode = $options['mode'] ?? 'balanced';   // concise | balanced | deep
        $surface = $options['surface'] ?? 'generic';
        $audience = $options['audience'] ?? 'user';
        $tone = $options['tone'] ?? 'neutral';

        $inferenceUrl = config('services.inference.url');

        // Best-effort LLM enhancement if inference plane is available
        if (! empty($inferenceUrl)) {
            try {
                $response = Http::timeout(8)
                    ->retry(1, 300)
                    ->post(rtrim($inferenceUrl, '/').'/generate', [
                        'prompt' => $this->buildEnhancementPrompt($trimmed, $mode, $surface, $audience, $tone),
                        'system_prompt' => $this->systemPrompt(),
                        'model' => config('services.spidernet.prompt_enhancer_model', 'gpt-4o-mini'),
                        'temperature' => 0.2,
                        'max_tokens' => 700,
                        'tenant_id' => 'system',
                        'cost_ceiling' => 0.05,
                        'tenant_tier' => 'growth',
                    ]);

                if ($response->successful()) {
                    $text = (string) ($response->json('text') ?? '');
                    if ($text !== '') {
                        return [
                            'original' => $input,
                            'enhanced' => trim($text),
                            'mode' => 'inference',
                            'surface' => $surface,
                            'notes' => ['Enhanced via inference plane'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to deterministic enhancer
            }
        }

        return [
            'original' => $input,
            'enhanced' => $this->deterministicEnhance($trimmed, $mode, $surface),
            'mode' => 'deterministic',
            'surface' => $surface,
            'notes' => ['Enhanced via deterministic fallback'],
        ];
    }

    // -----------------------------------------------------------------------
    // Inference prompt assembly
    // -----------------------------------------------------------------------

    private function systemPrompt(): string
    {
        return <<<'SYS'
You are a prompt-enhancement engine for SpiderNetOS. Transform a terse user
prompt into a precise, unambiguous instruction while strictly preserving the
original intent.

Rules:
- Output ONLY the enhanced prompt text. No preamble, no commentary.
- Never invent requirements not implied by the original prompt; mark optional
  assumptions explicitly with "Assumption:".
- Respect tenant-scoped operational context when implied (cost ceilings,
  approvals, tenant isolation).
- Keep the enhanced prompt actionable for an AI execution system and
  readable by a non-engineer.
SYS;
    }

    private function buildEnhancementPrompt(string $input, string $mode, string $surface, string $audience, string $tone): string
    {
        $surfaceHint = match ($surface) {
            'atlas_chat' => 'Target: Atlas conversational execution (multi-step orchestration).',
            'agent_builder' => 'Target: Agent behavior specification (role, goals, constraints, tools).',
            'flow_builder' => 'Target: Flow/DAG step specification (inputs, side effects, success criteria).',
            default => 'Target: generic SpiderNetOS operator prompt.',
        };

        return <<<PROMPT
Enhance the following operator prompt.

{$surfaceHint}
Mode: {$mode}        Audience: {$audience}        Tone: {$tone}

Structure the enhanced prompt using these sections where sensible:
  1) Objective
  2) Context and assumptions
  3) Required inputs (types, formats, examples)
  4) Tasks / steps (explicit, sequential)
  5) Constraints and guardrails
  6) Output specification (format, structure)
  7) Evaluation criteria

Preserve the original intent exactly. Do NOT invent unrelated requirements.
Return only the enhanced prompt text.

Original prompt:
<<<
{$input}
>>>
PROMPT;
    }

    // -----------------------------------------------------------------------
    // Deterministic fallback
    // -----------------------------------------------------------------------

    private function deterministicEnhance(string $input, string $mode, string $surface): string
    {
        $surfaceGuard = match ($surface) {
            'atlas_chat' => 'Runtime: Atlas orchestration plane. Respect tenant isolation and cost ceiling checks.',
            'agent_builder' => 'Runtime: Agent specification. Declare role, goals, tools, and explicit failure modes.',
            'flow_builder' => 'Runtime: Flow DAG node. Declare inputs, side effects, and acceptance conditions.',
            default => 'Runtime: SpiderNetOS control plane. Maintain tenant isolation and event-sourced writes.',
        };

        $lines = [
            '1) Objective',
            '   '.$input,
            '',
            '2) Context and assumptions',
            '   - '.$surfaceGuard,
            '   - Assume the caller is authenticated and tenant-scoped.',
            '',
            '3) Required inputs',
            '   - Derive any parameters from the original prompt; where ambiguous, prompt the user.',
            '',
            '4) Tasks / steps',
            '   - Restate the objective in one sentence.',
            '   - Execute the requested action using the minimum set of agents/tools required.',
            '   - Verify outcome against the acceptance criteria before returning.',
            '',
            '5) Constraints and guardrails',
            '   - Respect tenant isolation and pre-execution cost checks.',
            '   - Never bypass approval policies if the action modifies shared state.',
            '',
            '6) Output specification',
            '   - Return deterministic steps, explicit success criteria, and traceable execution artifacts.',
            '',
            '7) Evaluation criteria',
            '   - Success = objective fulfilled AND no guardrail breached AND audit trail emitted.',
        ];

        if ($mode === 'deep') {
            $lines[] = '   - Include: replay verification plan, divergence detection checkpoints, rollback strategy.';
        } elseif ($mode === 'concise') {
            $lines[] = '   - Keep the execution plan minimal but complete.';
        }

        return implode("\n", $lines);
    }
}
