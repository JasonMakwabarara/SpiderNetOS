<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Services\Agents\RunContext;

/**
 * A tool the runtime can hand an agent (plan D4). Draft-only writes: no
 * tool ever sends or publishes — sends are approvals applied by
 * ArtifactApplier. Risk drives the autonomy ladder in ToolGateway:
 *
 *   read         — reads the brain / a connector; never gated by the ladder
 *   draft        — writes only into the agent's own workspace (drafts,
 *                  scratch) or asks a human for review; never gated
 *   write        — changes shared state (CRM stage, brain proposal…)
 *   send         — leaves the tenant (email, DM, post); needs agents.tools.send
 *   irreversible — cannot be undone (payment, delete…); needs agents.tools.irreversible
 */
interface ToolContract
{
    public const RISK_READ = 'read';

    public const RISK_DRAFT = 'draft';

    public const RISK_WRITE = 'write';

    public const RISK_SEND = 'send';

    public const RISK_IRREVERSIBLE = 'irreversible';

    public const RISKS = [self::RISK_READ, self::RISK_DRAFT, self::RISK_WRITE, self::RISK_SEND, self::RISK_IRREVERSIBLE];

    /** Dotted tool name, e.g. `brain.read`, `drafts.save_sequence`. */
    public function name(): string;

    public function description(): string;

    /** JSON Schema (draft-07 subset) for the params object. */
    public function schema(): array;

    /** One of self::RISKS. */
    public function risk(): string;

    /** Connector provider that must be connected (tenant_integrations.provider), or null. */
    public function requiresConnector(): ?string;

    public function execute(RunContext $ctx, array $params): ToolResult;
}
