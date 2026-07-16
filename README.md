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
    secrets:
      OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
      EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.DOCS_AGENT_EXTERNAL_PACKAGE_SOURCE_POLICY }}
```

Docs Agent targets the calling repository and forwards its scoped `${{ github.token }}` to WP Codebox for checkout and publication. The consumer must grant `contents: write`, `pull-requests: write`, and `issues: write`; no `ACCESS_TOKEN` secret is required for this same-repository contract. `OPENAI_API_KEY` is optional in the reusable workflow schema but required for a live `run_agent: true` OpenAI run. `EXTERNAL_PACKAGE_SOURCE_POLICY` is a separate required secret: WP Codebox uses it to authorize the selected Docs Agent package and the complete WordPress-native runtime closure. Replace the prior v1 secret value with the exact value below; its secret name and workflow mapping are unchanged.

Configure `DOCS_AGENT_EXTERNAL_PACKAGE_SOURCE_POLICY` with this exact v1 JSON value:

```json
{"version":1,"repositories":{"automattic/docs-agent":["bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json","bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json","bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json","bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json","bundles/skills-agent/native/skills-maintenance-agent.agent.json"]},"runtime_sources":{"automattic/agents-api":["."],"wordpress/php-ai-client":["."]},"runtime_artifacts":[{"url":"https://downloads.wordpress.org/plugin/ai-provider-for-openai.1.0.3.zip","sha256":"48f3c0c714b3164cda79d320829830d5a0ea1116e0b19653da8af898a22d3bb6"}]}
```

The policy authorizes five exact public Docs Agent packages, two exact runtime git repository/root pairs, and the checksum-pinned OpenAI provider ZIP. It does not grant target-repository publication access.

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
    secrets:
      OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
      EXTERNAL_PACKAGE_SOURCE_POLICY: ${{ secrets.DOCS_AGENT_EXTERNAL_PACKAGE_SOURCE_POLICY }}
```

## Workflow Inputs

The consumer API is product-level. Consumer repositories configure the documentation lane, target branch, writable paths, executable verification commands, drift checks, and run gating through reusable workflow inputs.

| Input | Default | Description |
| --- | --- | --- |
| `audience` | `technical` | `technical` for developer/operator docs, `user` for non-technical product docs, or `skills` for live skill upkeep. |
| `run_kind` | `maintenance` | `bootstrap` establishes initial documentation and requires publication; `maintenance` permits a no-change result. |
| `base_ref` | `main` | Base branch or ref for the maintenance PR. |
| `docs_branch` | `docs-agent/docs-upkeep` | Stable branch reused for the canonical Docs Agent PR. |
| `writable_paths` | `README.md,docs/**` | Comma-separated allowlist of paths Docs Agent may edit. |
| `verification_commands` | `[]` | JSON array of canonical runner verification commands executed in the target workspace. |
| `drift_checks` | `[]` | JSON array of canonical runner drift checks executed after verification. |
| `prompt` | empty | Optional additional maintenance instruction. |
| `run_agent` | `true` | Set `false` to skip after deterministic preflight says docs are current. |
| `dry_run` | `false` | Set `true` to validate the prepared task without starting a live agent run. |

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

WP Codebox v0.12.21 uploads a reviewer-safe workflow-result projection with public control and publication fields plus canonical transcript provenance. It excludes raw `runtime_result`, `outputs.engine_data`, model/provider/tool payloads, source content, private paths, and secrets. The canonical `codebox-transcript` remains the bounded tool-observability surface: its pre-sanitization reviewer-evidence descriptor records the trusted artifact-relative path, schema, verified source digest, and size, which the uploader revalidates before producing the `wp-codebox/reviewer-agent-transcript/v1` projection.

Docs Agent owns native package selection, lane, artifact, prompt, and workspace mapping. Execution, credentials, AI provider selection, sandboxing, and publication are runner-owned concerns outside this repository.

Portable recipe fields include `docsAgent`, `runner.writablePaths`, caller-owned `runner.validationDependencies`, artifacts, verification commands, drift checks, and review output mapping suggestions.

## Pull Request Behavior

Docs Agent opens or updates one canonical PR for the configured branch.

- If the selected surface is current, the run succeeds with no changes.
- If maintenance is needed, changes are written only under `writable_paths`.
- If the canonical PR is already open, later runs reuse the same `docs_branch` and PR instead of creating duplicates.
- `validation_dependencies` is an optional caller-owned reusable-workflow input. It is passed through the portable recipe and runs before verification commands when a live runner execution needs setup.
- `job_status`, `transcript_summary`, `credential_mode`, `success_requires_pr`, `validation_dependencies`, and bounded `projected_outputs_json` are exposed as reusable workflow outputs. A `run_agent: false` call returns `job_status: skipped`; a `dry_run: true` call validates without starting a model run. `OPENAI_API_KEY` is only required for a live OpenAI run and is never included in recipes, workflow outputs, or artifacts. Bootstrap lanes require a published pull request and its projected URL for success; maintenance lanes allow a no-change result with no publication projection. Typed artifact declarations remain optional and are exposed as `declared_artifacts_json` for review/debugging. Raw engine data is not exposed as a workflow output or uploaded reviewer artifact.

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

## Native Packages

Docs Agent has one canonical architecture: five standalone native Agents API packages selected by the reusable workflow:

- `bundles/technical-docs-agent/native/technical-docs-bootstrap-agent.agent.json`
- `bundles/technical-docs-agent/native/technical-docs-maintenance-agent.agent.json`
- `bundles/user-docs-agent/native/user-docs-bootstrap-agent.agent.json`
- `bundles/user-docs-agent/native/user-docs-maintenance-agent.agent.json`
- `bundles/skills-agent/native/skills-maintenance-agent.agent.json`

These five `.agent.json` files are the complete executable package surface. Each package is the sole executable instruction authority for its lane. The reusable workflow maps `audience` and `run_kind` to exactly one package and its canonical agent slug; it does not select a separate manifest, flow, pipeline, or memory envelope. Every descriptor uses the package-source revision `7b2df969c34de112ec7ad13189ba94226a7f76f3`, independently of the revision that invokes the reusable workflow, and supplies a byte-level `sha256-bytes-v1` digest.

Package updates advance the package-source revision and all five declared digests atomically. The immutable-source validator reads each package blob from that Git revision, recomputes its digest and canonical slug, and rejects a descriptor that does not match those historical bytes.

All five packages support direct import through `wp_agent_import_runtime_bundles()` and retain the source-grounded workspace-only editing boundary and required workspace-write gate.

### Compatibility Impact

Direct consumers of the removed legacy `manifest.json`, `flows/`, `pipelines/`, or memory envelopes must migrate to the corresponding native `.agent.json` package listed above. Consumers of `maintain-docs.yml` already use these native packages and require no workflow migration.

## Workflow Operation

Consumer repositories call `.github/workflows/maintain-docs.yml`. The workflow accepts the product-level inputs above, selects the matching native package, and invokes the native runner contract. The runner publishes or updates the configured Docs Agent pull request when files change.

Maintainers invoke `maintain-docs.yml` from a consumer workflow to run the native reusable workflow contract.

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
php tests/validate-docs-agent-packages.php
php tests/validate-external-native-package-sources.php
php tests/repair-docs-links-smoke.php
WP_CODEBOX_DIR=/path/to/wp-codebox php tests/validate-wp-codebox-run-agent-task-contract.php
actionlint .github/workflows/*.yml
git diff --check
```

The native import test uses the maintained Agents API pure-PHP smoke harness; clone `Automattic/agents-api` and run:

```bash
AGENTS_API_DIR=/path/to/agents-api php tests/native-agent-import.php
```

It imports every native package through `wp_agent_import_runtime_bundles()`, verifies registration and preserved write-gate defaults, and invokes the default native chat handler far enough to resolve each registered agent. It intentionally fails when `AGENTS_API_DIR` is unavailable rather than treating an unexecuted importer as a passing test. It does not execute a model turn because the packages intentionally leave provider/model selection to the caller.

The `Docs Agent Tests` GitHub Actions workflow runs on pull requests and pushes. It fetches Docs Agent history so it can run the immutable native package source validator, then runs the structural package validator, docs-link repair smoke test, and native importer integration test against `Automattic/agents-api` at `78e2dd409010f98fa4d26cdd72572117384ab18d`, the merged commit from [Agents API #428](https://github.com/Automattic/agents-api/pull/428).
