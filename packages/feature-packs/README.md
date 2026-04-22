# SpiderNetOS Feature Packs

This directory contains the official SpiderNetOS Feature Pack registry and authoring tools.

## Structure

```
packages/feature-packs/
├── README.md                          # This file
├── schema/
│   └── feature-pack.schema.json       # JSON Schema for pack validation
├── real-estate-crm/                   # Reference implementation
│   ├── pack.yaml
│   ├── README.md
│   ├── state-model.yaml
│   ├── agents/
│   ├── flows/
│   └── policies/
└── [future packs...]
```

## Authoring a Feature Pack

1. **Copy the skeleton:**
   ```bash
   cp -r real-estate-crm my-new-pack
   cd my-new-pack
   ```

2. **Edit `pack.yaml`:**
   - Set unique `metadata.id` (kebab-case)
   - Define `spec.provides` (agents, flows, chains)
   - Set `spec.targets` (optimization goals)

3. **Define state model:**
   - Edit `state-model.yaml`
   - Define your STE chain extensions
   - Use `pack.{id}.{chain}` namespace

4. **Configure agents:**
   - Create YAML files in `agents/`
   - Define triggers, capabilities, policies
   - Set `shared: true/false` for cross-tenant learning

5. **Build flows:**
   - Create DAG templates in `flows/`
   - Reference agents by `{pack_id}.{agent_id}`

6. **Validate:**
   ```bash
   spidernet pack validate ./my-new-pack
   ```

7. **Test locally:**
   ```bash
   spidernet pack install ./my-new-pack --dev
   ```

8. **Sign and publish:**
   ```bash
   spidernet pack sign ./my-new-pack --key my-key
   spidernet pack publish ./my-new-pack --registry official
   ```

## Schema Validation

All packs must validate against `schema/feature-pack.schema.json`:

```bash
# Using official validator
spidernet pack validate ./my-pack

# Using generic JSON Schema validator
ajv validate -s schema/feature-pack.schema.json -d my-pack/pack.yaml
```

## Guidelines

### Agent Design

- **Start with triggers:** What events start this agent?
- **Define capabilities:** What can it actually do?
- **Set policies:** What should it optimize for?
- **Use shared policies** for common optimizations (follow-up timing, ad copy)
- **Use private policies** for competitive advantages (proprietary scoring models)

### State Model Design

- **Map the customer journey:** What states does a user pass through?
- **Identify drop-off points:** Where do users abandon?
- **Define events:** What observable actions trigger transitions?
- **Namespace everything:** Use `pack.{id}.{chain}` prefix

### Flow Design

- **One purpose per flow:** Don't combine lead capture with retention
- **Use decision nodes:** Branch based on scores, preferences, timing
- **Set timeouts:** Don't wait forever for user responses
- **Handle failures:** Always have an error path

### Policy Design

- **Start with data:** What do you already know works?
- **Use Thompson Sampling:** Balance exploration vs exploitation
- **Enable cross-tenant learning** for common patterns
- **Keep private policies** for competitive moats

## Related

- `docs/feature-packs/SPEC.md` — Full specification
- `docs/feature-packs/LIFECYCLE.md` — Pack lifecycle
- `docs/feature-packs/EXAMPLE-real-estate-crm.md` — Worked example
- `docs/adr/0001-agent-roster-reconciliation.md` — Dynamic agents
