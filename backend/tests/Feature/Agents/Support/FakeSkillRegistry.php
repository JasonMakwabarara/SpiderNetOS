<?php

declare(strict_types=1);

namespace Tests\Feature\Agents\Support;

use App\Services\Skills\SkillCard;
use App\Services\Skills\SkillRegistry;

/**
 * The real SkillRegistry (packages/skills on disk) plus extra cards a test
 * builds with SkillCard::fromArray(). Bound as the container instance so
 * SkillInstaller / SkillPromptBuilder / SkillOutputValidator see it too.
 */
final class FakeSkillRegistry extends SkillRegistry
{
    /** @var array<string, SkillCard> */
    private array $extra = [];

    /** @param array<string, SkillCard> $extra */
    public function __construct(array $extra = [], ?string $root = null)
    {
        parent::__construct($root);
        foreach ($extra as $card) {
            $this->extra[$card->id] = $card;
        }
    }

    public function add(SkillCard $card): self
    {
        $this->extra[$card->id] = $card;

        return $this;
    }

    public function all(): array
    {
        $cards = parent::all() + $this->extra;
        ksort($cards);

        return $cards;
    }

    public function get(string $slug): ?SkillCard
    {
        return $this->extra[$slug] ?? parent::get($slug);
    }

    public function has(string $slug): bool
    {
        return isset($this->extra[$slug]) || parent::has($slug);
    }

    /**
     * A minimal agentic card whose prompt lives in a temp folder.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function agenticCard(array $overrides = []): SkillCard
    {
        $folder = rtrim(sys_get_temp_dir(), '/\\').'/spidernet-agentic-card';
        if (! is_dir($folder.'/prompts')) {
            mkdir($folder.'/prompts', 0777, true);
        }
        file_put_contents($folder.'/prompts/task.md', "TASK: Read the offer file with the brain.read tool, then reply with {\"final\":{\"note\":\"<one line>\"}}.\nTopic: {{inputs.topic}}\n");

        $card = array_replace_recursive([
            'id' => 'agentic-note-test',
            'display_name' => 'Agentic Note Test',
            'version' => '0.1.0',
            'pillar' => 'operations',
            'map_node' => 'Operations › Notes',
            'core_agent' => 'prism',
            'runs_on' => 'growth',
            'pack_id' => null,
            'mode' => 'agentic',
            'at_a_glance' => 'Reads one brain file through a tool call and files a note.',
            'covers' => 'A test card for the agentic loop.',
            'brain' => [
                'requires' => [['path' => 'business/profile.md', 'sections' => ['What we do']]],
                'reads' => ['offer/offer.md'],
            ],
            'tools' => ['brain.read', 'drafts.save', 'crm.update_stage'],
            'inputs' => ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']]],
            'outputs' => [[
                'kind' => 'note',
                'primary' => true,
                'schema' => ['type' => 'object', 'required' => ['note'], 'properties' => ['note' => ['type' => 'string', 'minLength' => 5]]],
            ]],
            'pipeline' => ['default_level' => 'human_led', 'ladder' => ['human_led', 'assisted', 'autonomous']],
            'triggers' => [['type' => 'manual']],
            'cost_budget' => ['daily_limit_usd' => 1.0, 'per_run_usd' => 0.5],
            'approval' => ['resource_type' => 'agent_artifact', 'required' => 'never'],
            'model' => ['heavy' => false, 'temperature' => 0.1, 'max_tokens' => 400],
            'limits' => ['max_iterations' => 4, 'max_tool_calls' => 6, 'max_artifacts' => 3],
            'run' => ['kind' => 'agent', 'entry_path' => '/skills/agentic-note-test', 'prompt' => 'prompts/task.md', 'post_actions' => ['drafts.save']],
        ], $overrides);

        return SkillCard::fromArray($card, $folder);
    }
}
