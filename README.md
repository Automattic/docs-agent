# Docs Agent

Automated documentation upkeep for GitHub repositories.

Docs Agent helps repositories keep source-grounded documentation current. A consumer repository adds one reusable GitHub Actions workflow, chooses the documentation audience, points Docs Agent at the paths it may edit, and gets either a clean no-op run or one reusable documentation pull request.

## What It Maintains

- **Technical docs**: developer, site-owner, operator, contributor, and integrator documentation.
- **User docs**: non-technical product documentation for frontend users.

Docs Agent is designed for living docs, not shallow generated summaries. It should preserve accurate reference depth while adding an adoption-oriented entry path that explains project names, concepts, and where to start before readers reach deep architecture or task material.

## Quick Start

Create `.github/workflows/docs-agent.yml` in the repository whose docs should be maintained:

```yaml
name: Docs Agent

on:
  workflow_dispatch:
  schedule:
    - cron: '17 6 * * *'

permissions:
  contents: write
  pull-requests: write
  issues: write

jobs:
  docs-agent:
    uses: Automattic/docs-agent/.github/workflows/maintain-docs.yml@main
    with:
      audience: technical
      base_ref: trunk
      docs_branch: docs-agent/my-repo-docs
      writable_paths: README.md,docs/**
```

For repositories that run their own preflight detection, pass `run_agent: false` when no docs work is needed. The workflow records a deterministic skipped run instead of booting the agent runtime.

```yaml
jobs:
  detect:
    runs-on: ubuntu-latest
    outputs:
      should_run: ${{ steps.detect.outputs.should_run }}
    steps:
      - id: detect
        run: printf 'should_run=true\n' >> "$GITHUB_OUTPUT"

  docs-agent:
    needs: detect
    uses: Automattic/docs-agent/.github/workflows/maintain-docs.yml@main
    with:
      audience: technical
      base_ref: trunk
      docs_branch: docs-agent/my-repo-docs
      writable_paths: README.md,docs/**
      run_agent: ${{ needs.detect.outputs.should_run == 'true' }}
```

## Workflow Inputs

The consumer API is product-level. Consumer repositories do not need to configure bundle paths, pipeline slugs, runner tools, or implementation-specific runtime details.

| Input | Default | Description |
| --- | --- | --- |
| `audience` | `technical` | `technical` for developer/operator docs, or `user` for non-technical product docs. |
| `base_ref` | `main` | Base branch or ref for the documentation PR. |
| `docs_branch` | `docs-agent/docs-upkeep` | Stable branch reused for the canonical Docs Agent PR. |
| `writable_paths` | `README.md,docs/**` | Comma-separated allowlist of paths Docs Agent may edit. |
| `prompt` | empty | Optional additional maintenance instruction. |
| `model` | `gpt-5.5` | Model used by Docs Agent. |
| `run_agent` | `true` | Set `false` to skip after deterministic preflight says docs are current. |

## Pull Request Behavior

Docs Agent opens or updates one canonical PR for the configured branch.

- If docs are current, the run succeeds with no changes.
- If docs need work, changes are written only under `writable_paths`.
- If the canonical PR is already open, later runs reuse the same `docs_branch` and PR instead of creating duplicates.
- Transcript and projected engine data are exposed as reusable workflow outputs, and the transcript is uploaded as a workflow artifact for review/debugging.

## Documentation Quality Bar

Docs Agent should produce docs that help a new reader adopt the project without sacrificing accuracy.

- Start with a clear introduction path: what the project is, core nomenclature, key concepts, and where to begin.
- Preserve source-grounded technical or product depth after the onboarding layer.
- Link behavior back to source evidence such as code, tests, configuration, existing docs, issues, or pull requests.
- Keep generated changes focused and reviewable.
- Prefer a no-op result over speculative or unsupported documentation.

## Audience Guidance

Use `audience: technical` for developer-facing docs:

- Architecture and lifecycle
- APIs, hooks, filters, abilities, commands, and data contracts
- Extension points and integration guidance
- Local development, tests, release workflows, and operations
- Contributor guidance

Use `audience: user` for frontend/product docs:

- Product overview and setup
- Visible settings and common tasks
- Expected outcomes and permissions
- Compatibility, troubleshooting, and FAQs
- User-visible release behavior

Run separate workflows or branches for separate audiences. Avoid letting both audiences edit the same docs index in one pass.

## Writable Scope

Keep the writable scope narrow. It is the main safety boundary for generated changes.

- Technical docs commonly use `README.md,docs/**`.
- User docs commonly use a dedicated namespace such as `docs/user/**`.

## Examples

- `examples/consumer-workflow.yml`: scheduled consumer workflow using `maintain-docs.yml`.
- `examples/homeboy-runner-config.example.json`: lower-level runner config for maintainers debugging the implementation contract.

## Bundles

Docs Agent ships portable agent bundles used internally by the reusable workflow:

- `bundles/technical-docs-agent`: technical/developer documentation maintenance.
- `bundles/user-docs-agent`: non-technical product documentation maintenance.

The reusable workflow maps `audience` to the correct bundle, agent identity, pipeline, and maintenance flow.

## Implementation Notes

Consumer repositories should call `.github/workflows/maintain-docs.yml`. The workflow internally runs the existing Homeboy, WP Codebox, and Data Machine agent runner stack, but those are implementation details of this repository's automation layer.

Maintainers may still use `.github/workflows/docs-agent.yml` for central dispatch/debugging against an arbitrary `target_repo` when GitHub App credentials are available.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
php tests/repair-docs-links-smoke.php
```

CI validates both bundles with `tests/docs-agent.validate-bundle-spec.json`.
