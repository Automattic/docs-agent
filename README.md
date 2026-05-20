# Docs Agent

Reusable Data Machine documentation-agent package for GitHub repositories.

This repo ships multiple portable agent bundles. Consumers choose the bundle that matches the documentation audience instead of running one shared agent with different lanes.

## Bundles

- `bundles/technical-docs-agent`: developer, site-owner, operator, contributor, and integrator documentation.
- `bundles/user-docs-agent`: non-technical product documentation for frontend users.

Both bundles run in the same Homeboy/Data Machine runner stack. Each imports a distinct agent identity, memory, pipeline, flow set, and documentation standard.

## How The Pieces Fit

```text
Target GitHub repo
        |
        | read context, write allowed docs paths, open PR
        v
Data Machine Code GitHub/workspace tools
        |
        | tools exposed to selected bundle agent
        v
Data Machine flow + pipeline
        |
        | agent loop and job execution
        v
Agents API + model provider
        |
        | reusable runtime primitives + LLM call
        v
Selected Docs Agent bundle
```

The consumer repo does not need to contain these bundles. It only needs a Homeboy runner configuration that says which bundle, flow, target repo, credentials, and writable paths to use.

## Repositories Involved

- Docs Agent bundles: https://github.com/Automattic/docs-agent
- Agents API runtime primitives: https://github.com/Automattic/agents-api
- Data Machine agent, bundle, flow, and pipeline runtime: https://github.com/Extra-Chill/data-machine
- Data Machine Code GitHub tools and PR-writing abilities: https://github.com/Extra-Chill/data-machine-code
- OpenAI provider for the WordPress AI Client: https://github.com/WordPress/ai-provider-for-openai
- Homeboy command runner and artifact framework: https://github.com/Extra-Chill/homeboy
- Homeboy WordPress/Playground extension layer: https://github.com/Extra-Chill/homeboy-extensions

## Technical Docs Agent

- Bundle path: `bundles/technical-docs-agent`
- Agent slug: `technical-docs-agent`
- Pipeline slug: `technical-docs-pipeline`
- Bootstrap flow slug: `technical-docs-bootstrap-flow`
- Maintenance flow slug: `technical-docs-maintenance-flow`
- Maintenance alias: `technical-docs-flow`

Use this bundle for developer-facing docs: architecture, APIs, hooks, filters, abilities, commands, extension points, lifecycle, data contracts, local development, tests, release workflows, operations, and contributor guidance.

The technical bundle can write in the repository's existing developer-docs namespace, commonly `README.md` and `docs/**`.

## User Docs Agent

- Bundle path: `bundles/user-docs-agent`
- Agent slug: `user-docs-agent`
- Pipeline slug: `user-docs-pipeline`
- Bootstrap flow slug: `user-docs-bootstrap-flow`
- Maintenance flow slug: `user-docs-maintenance-flow`
- Maintenance alias: `user-docs-flow`

Use this bundle for non-technical product docs: what the product does, onboarding, product-surface setup, visible settings, common tasks, expected outcomes, permissions, compatibility, troubleshooting, and FAQ-worthy behavior.

The user bundle should write to its own product-docs namespace, commonly `docs/user/**`, with its own index such as `docs/user/README.md`. It writes for frontend consumers and keeps implementation evidence internal to the run.

## Setup For A Consumer Repo

Docs Agent is not Automattic-only. Anyone can use it if they can run the Homeboy/Data Machine runner stack and provide the required GitHub and model-provider credentials.

### 1. Choose The Audience

Choose exactly one bundle per run:

- Technical docs: `bundles/technical-docs-agent`
- User docs: `bundles/user-docs-agent`

Run separate PRs for separate audiences. Avoid letting both bundles edit the same docs index in one pass.

### 2. Choose The Writable Scope

Keep the writable scope narrow:

- Technical docs often use `README.md` and `docs/**`.
- User docs should use a dedicated namespace such as `docs/user/**`.

The writable scope is the main safety boundary. Keep it explicit.

### 3. Create Credentials

The runner needs:

- A GitHub credential that can read the target repo, create branches, write configured docs files, and open pull requests.
- `OPENAI_API_KEY` or the equivalent model-provider credential.

Use repository or organization secrets in CI. Do not commit credentials into runner config.

### 4. Add A Runner Config

Start from `examples/homeboy-runner-config.example.json` and change these fields:

- `bundle_path_in_repo`: selected bundle path.
- `agent_slug`: selected bundle agent slug.
- `pipeline_slug` and `flow_slug`: selected bundle pipeline and flow.
- `target_repo`: GitHub `OWNER/REPO` for the repository being documented.
- `allowed_repos`: usually the same single `OWNER/REPO`.
- `tool_recorders[].forced_parameters.allowed_file_paths`: exact docs path scope the agent may write.
- `bench_env`: maps CI secrets into the runner environment.

For an initial technical docs pass, use:

```json
{
  "bundle_path_in_repo": "bundles/technical-docs-agent",
  "agent_slug": "technical-docs-agent",
  "pipeline_slug": "technical-docs-pipeline",
  "flow_slug": "technical-docs-bootstrap-flow"
}
```

For ongoing technical docs maintenance, use:

```json
{
  "bundle_path_in_repo": "bundles/technical-docs-agent",
  "agent_slug": "technical-docs-agent",
  "pipeline_slug": "technical-docs-pipeline",
  "flow_slug": "technical-docs-maintenance-flow"
}
```

For an initial user docs pass, use:

```json
{
  "bundle_path_in_repo": "bundles/user-docs-agent",
  "agent_slug": "user-docs-agent",
  "pipeline_slug": "user-docs-pipeline",
  "flow_slug": "user-docs-bootstrap-flow"
}
```

For ongoing user docs maintenance, use:

```json
{
  "bundle_path_in_repo": "bundles/user-docs-agent",
  "agent_slug": "user-docs-agent",
  "pipeline_slug": "user-docs-pipeline",
  "flow_slug": "user-docs-maintenance-flow"
}
```

## Runner Contract

Consumers should pass the generic runner a config equivalent to `examples/homeboy-runner-config.example.json`.

Important fields:

- `bundle_repo`: `https://github.com/Automattic/docs-agent.git`
- `bundle_ref`: a branch, tag, or SHA from this repo
- `bundle_path_in_repo`: selected bundle path
- `agent_slug`: selected bundle agent slug
- `pipeline_slug` and `flow_slug`: selected by the consuming repo
- `target_repo` and `allowed_repos`: GitHub repository scope
- `success_requires_pr`: `false` for maintenance, usually `true` for bootstrap when the consumer expects a first draft PR
- `tool_recorders[].forced_parameters.allowed_file_paths`: hard writable path scope

The runner should treat both outcomes as successful when bootstrap PRs are not required:

- `pr_opened`: docs changes were needed and a pull request was opened.
- `no_changes`: docs are already aligned and no pull request was needed.

## Review The Output PR

Docs Agent opens a documentation PR only when it changes files. Review it like any other generated change:

- Check that the docs match current behavior.
- Confirm the agent stayed inside the intended writable paths.
- Confirm the PR scope is one coherent docs update.
- Confirm the chosen audience is correct for every page.
- Edit, close, or merge based on normal repository review standards.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
php tests/repair-docs-links-smoke.php
```

CI validates both bundles with `tests/docs-agent.validate-bundle-spec.json`.
