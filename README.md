# Docs Agent

Automated documentation and skills upkeep for GitHub repositories.

Docs Agent helps repositories keep source-grounded maintenance surfaces current. A consumer repository adds one reusable GitHub Actions workflow, chooses the lane, points Docs Agent at the paths it may edit, and gets either a clean no-op run or one reusable pull request.

## What It Maintains

- **Technical docs**: developer, site-owner, operator, contributor, and integrator documentation.
- **User docs**: non-technical product documentation for frontend users.
- **Skills**: live agent skill instructions and generated packaged skill outputs.

Docs Agent is designed for living docs and live instruction upkeep, not shallow generated summaries. The technical docs lane can explain skills for humans; the skills lane maintains executable skill instructions themselves.

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

The consumer API is product-level. Consumer repositories configure the documentation lane, target branch, writable paths, executable verification commands, drift checks, and run gating through reusable workflow inputs.

| Input | Default | Description |
| --- | --- | --- |
| `audience` | `technical` | `technical` for developer/operator docs, `user` for non-technical product docs, or `skills` for live skill upkeep. |
| `base_ref` | `main` | Base branch or ref for the maintenance PR. |
| `docs_branch` | `docs-agent/docs-upkeep` | Stable branch reused for the canonical Docs Agent PR. |
| `writable_paths` | `README.md,docs/**` | Comma-separated allowlist of paths Docs Agent may edit. |
| `verification_commands` | `[]` | JSON array of canonical runner verification commands executed in the target workspace. |
| `drift_checks` | `[]` | JSON array of canonical runner drift checks executed after verification. |
| `prompt` | empty | Optional additional maintenance instruction. |
| `run_agent` | `true` | Set `false` to skip after deterministic preflight says docs are current. |

`verification_commands` and `drift_checks` are executable runner inputs. Docs Agent keeps the target repository as the only writable PR boundary; the reusable runner executes the selected native agent task.

## Review Artifacts

Docs Agent declares the review artifacts it expects the runner to materialize as typed artifacts:

| Artifact | Schema | Purpose |
| --- | --- | --- |
| `docs_agent_transcript` | `docs-agent/transcript/v1` | Machine-readable run transcript. |
| `docs_agent_change_summary` | `docs-agent/change-summary/v1` | Reviewable summary of documentation or skill changes. |
| `docs_agent_verification_report` | `docs-agent/verification-report/v1` | Verification command results for the target workspace. |
| `docs_agent_drift_report` | `docs-agent/drift-report/v1` | Drift-check results for generated docs, skills, or packaged outputs. |
| `docs_agent_workspace_publication` | `docs-agent/workspace-publication/v1` | Canonical branch and pull request links published by the runner workspace. |

`maintain-docs.yml` writes `expected_artifacts` and `artifact_declarations` into a portable Docs Agent recipe and exposes the same declaration objects as `declared_artifacts_json`.

Docs Agent owns the Docs Agent-specific bundle, lane, artifact, prompt, and workspace mapping. Execution, credentials, AI provider selection, sandboxing, and publication are runner-owned concerns outside this repository.

Portable recipe fields include `docsAgent`, `runner.writablePaths`, artifacts, verification commands, drift checks, and review output mapping suggestions.

## Pull Request Behavior

Docs Agent opens or updates one canonical PR for the configured branch.

- If the selected surface is current, the run succeeds with no changes.
- If maintenance is needed, changes are written only under `writable_paths`.
- If the canonical PR is already open, later runs reuse the same `docs_branch` and PR instead of creating duplicates.
- Transcript and projected engine data are exposed as reusable workflow outputs, and typed artifact declarations are exposed as `declared_artifacts_json` for review/debugging.

## Quality Bar

Docs Agent should produce changes that help a new reader or agent adopt the project without sacrificing accuracy.

- Start with a clear introduction path for docs: what the project is, core nomenclature, key concepts, and where to begin.
- Preserve source-grounded technical or product depth after the onboarding layer.
- For skills, preserve prompt quality, routing behavior, tool-use policy, writable-path safety, packaging consistency, and focused review of behavior changes.
- Link behavior back to source evidence such as code, tests, configuration, existing docs, issues, or pull requests.
- Keep generated changes focused and reviewable.
- Prefer a no-op result over speculative or unsupported maintenance.

## Lane Guidance

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

Use `audience: skills` for live skill maintenance:

- Prompt instructions, routing behavior, and tool-use policy
- Writable-path guidance and verification expectations
- Generated packaged skill copies and plugin skill outputs
- Current upstream tool, build, package, and verification contracts from runner-provided context aliases

Run separate workflows or branches for separate lanes. Avoid letting docs lanes and the skills lane edit the same surfaces in one pass.

## Writable Scope

Keep the writable scope narrow. It is the main safety boundary for generated changes.

- Technical docs commonly use `README.md,docs/**`.
- User docs commonly use a dedicated namespace such as `docs/user/**`.
- Skills maintenance commonly uses `skills/**,plugins/**/skills/**,plugins/**/README.md`, plus generated MCP or plugin config files only when build scripts intentionally update them.

Run skills upkeep as its own scheduled lane with `verification_commands`, `drift_checks`, a dedicated branch such as `docs-agent/skills-upkeep`, and writable paths such as `skills/**,packages/**/skills/**,packages/**/README.md`.

## Examples

- `examples/consumer-workflow.yml`: scheduled consumer workflow using `maintain-docs.yml` for technical docs.
- `examples/runner-recipe.example.json`: recipe-oriented config for maintainers debugging the portable Docs Agent contract.

## Bundles

Docs Agent ships portable agent bundles selected by the reusable workflow:

- `bundles/technical-docs-agent`: technical/developer documentation maintenance.
- `bundles/user-docs-agent`: non-technical product documentation maintenance.
- `bundles/skills-agent`: live agent skill instruction and packaged-output maintenance.

The reusable workflow maps `audience` to the correct bundle, agent identity, pipeline, and maintenance flow.

## Workflow Operation

Consumer repositories call `.github/workflows/maintain-docs.yml`. The workflow accepts the product-level inputs above, selects the matching Docs Agent bundle, and invokes the native runner contract. The runner publishes or updates the configured Docs Agent pull request when files change.

Maintainers may still use `.github/workflows/docs-agent.yml` to prepare a recipe summary for an arbitrary `target_repo`.

## Review The Output PR

Docs Agent opens a PR only when it changes files. Review it like any other generated change:

- Check that the changed surface matches current behavior.
- Confirm the agent stayed inside the intended writable paths.
- Confirm the PR scope is one coherent maintenance update.
- Confirm the chosen lane is correct for every changed file.
- Edit, close, or merge based on normal repository review standards.

For skills PRs, also confirm the live instructions match current upstream tool behavior, generated package outputs are aligned after build, and verification results such as `pnpm build`, `pnpm verify`, and drift checks are included in the PR.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
php tests/repair-docs-links-smoke.php
```

CI validates all bundles with `tests/docs-agent.validate-bundle-spec.json`.
