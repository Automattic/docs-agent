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
      source_delta: '[{"id":"scheduled-source-window","source_refs":["src/**","tests/**"],"requires_documentation_change":false}]'
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
      source_delta: '[{"id":"preflight-delta","source_refs":["src/**","tests/**"],"requires_documentation_change":false}]'
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
| `require_pr` | `false` | Require a published target-repository PR for success. Bootstrap always requires publication; set this for maintenance acceptance or remediation runs that must prove a real PR was opened. |
| `base_ref` | `main` | Base branch or ref for the maintenance PR. |
| `docs_branch` | `docs-agent/docs-upkeep` | Stable branch reused for the canonical Docs Agent PR. |
| `writable_paths` | `README.md,docs/**` | Comma-separated allowlist of paths Docs Agent may edit. |
| `context_repositories` | `[]` | JSON array of read-only evidence repositories with `alias`, `repository`, and optional `revision`. They are authorized for native GitHub reads and remain outside the writable workspace. |
| `bootstrap_contract` | `{}` | Positive bootstrap criteria: `required_paths`, `required_globs`, `entry_points`, and `forbidden_phrases`. Bootstrap calls must provide at least one path, glob, or entry point criterion. |
| `source_delta` | `[]` | Caller-bounded deltas with unique `id`, non-empty `source_refs`, and `requires_documentation_change`. Maintenance calls must override the empty default with at least one item; bootstrap may use its positive bootstrap contract and inventory mode instead. |
| `verification_commands` | `[]` | JSON array of canonical runner verification commands executed in the target workspace. |
| `drift_checks` | `[]` | JSON array of canonical runner drift checks executed after verification. |
| `prompt` | empty | Optional additional maintenance instruction. |
| `run_agent` | `true` | Set `false` to skip after deterministic preflight says docs are current. |
| `dry_run` | `false` | Set `true` to validate the prepared task without starting a live agent run. |

`verification_commands` and `drift_checks` are executable caller inputs. Docs Agent appends its own completion-contract check as a separate drift entry after them. The target repository remains the only writable PR boundary.

Bootstrap example criteria:

```yaml
run_kind: bootstrap
bootstrap_contract: >-
  {"required_paths":["README.md","docs/architecture.md","docs/setup.md"],"required_globs":[{"pattern":"docs/**/*.md","min":2}],"entry_points":[{"path":"README.md","must_link_to":["docs/architecture.md","docs/setup.md"]}],"forbidden_phrases":["TODO: document this"]}
```

Maintenance source-delta example:

```yaml
run_kind: maintenance
source_delta: >-
  [{"id":"runtime-contract","source_refs":["src/runtime/**","tests/runtime/**"],"requires_documentation_change":false}]
```

## Review Artifacts

Docs Agent declares the review artifacts it expects the runner to materialize as typed artifacts:

| Artifact | Schema | Purpose |
| --- | --- | --- |
| `docs_agent_transcript` | `docs-agent/transcript/v1` | Machine-readable run transcript. |
| `docs_agent_change_summary` | `docs-agent/change-summary/v1` | Reviewable summary of documentation or skill changes. |
| `docs_agent_verification_report` | `docs-agent/verification-report/v1` | Verification command results for the target workspace. |
| `docs_agent_drift_report` | `docs-agent/drift-report/v1` | Drift-check results for generated docs, skills, or packaged outputs. |
| `docs_agent_completion_report` | `docs-agent/completion-report/v1` | Host-observed completion report generated from caller inputs, Git, and filesystem checks. |
| `docs_agent_workspace_publication` | `docs-agent/workspace-publication/v1` | Canonical branch and pull request links published by the runner workspace. |

`maintain-docs.yml` writes `expected_artifacts` and `artifact_declarations` into a portable Docs Agent recipe and exposes the same declaration objects as `declared_artifacts_json`. The runtime declaration for `docs_agent_completion_report` remains `required: false` because WP Codebox may evaluate runtime-level required artifacts before post-command validation. The final completion drift entry is mandatory and declares `{name,type,path}`; after deterministic host validation, the validator atomically writes canonical JSON to `.codebox/agent-task-artifacts/docs-agent-completion-report.json`, and WP Codebox stages that declared command artifact for reviewers.

The reusable workflow and helper implementation are pinned to accepted revision `a6fe2d208e990a8d04104aa74aacbb8d1539fbc1`; `v0.12.29` remains only the `wp_codebox_release_ref` for released packaged runtime assets at `bc982947ec33c78160125026e16d357b7ece3ea1`.

WP Codebox v0.12.29 at `bc982947ec33c78160125026e16d357b7ece3ea1` uploads a reviewer-safe workflow-result projection with public control and publication fields plus canonical transcript provenance. This released workflow revision remains distinct from the fixed Docs Agent package revision. It excludes raw `runtime_result`, `outputs.engine_data`, model/provider/tool payloads, source content, private paths, secrets, Git-ignored verification artifacts such as pnpm's symlinked `node_modules` tree, and mutable `.codebox` runtime control files. Tracked or otherwise publishable symlinks remain rejected. Integrity failures retain bounded added, modified, and deleted path evidence, and non-Git workspaces retain a bounded filesystem snapshot fallback. Canonical GitHub repository identity is compared case-insensitively while pull-request URL syntax, pull number, target binding, and API resolution remain strict. Successful publication verification returns `{ valid: true }` without a failure-only `error`, while repository mismatches retain their exact diagnostic as fixed by [WP Codebox #1885](https://github.com/Automattic/wp-codebox/pull/1885). The canonical `codebox-transcript` remains the bounded tool-observability surface: its pre-sanitization reviewer-evidence descriptor records the trusted artifact-relative path, schema, verified source digest, and size, which the uploader revalidates before producing the `wp-codebox/reviewer-agent-transcript/v1` projection. Before apply-back, it validates that the runner seed and host workspace identities match; rejected patches retain identity, patch, and changed-file evidence for review. The pre-redaction trusted apply input fixed by [WP Codebox #1842](https://github.com/Automattic/wp-codebox/pull/1842) retains machine-applicable patch bytes privately, then removes them before durable artifact sanitization. The publication snapshot fixes are tracked in [WP Codebox #1845](https://github.com/Automattic/wp-codebox/pull/1845), [WP Codebox #1848](https://github.com/Automattic/wp-codebox/pull/1848), [WP Codebox #1852](https://github.com/Automattic/wp-codebox/pull/1852), [WP Codebox #1875](https://github.com/Automattic/wp-codebox/pull/1875), and [WP Codebox #1885](https://github.com/Automattic/wp-codebox/pull/1885). The published release assets are `01-wp-codebox.zip` and `02-wp-codebox-workspace-0.12.29.tgz`; its private package-recovery manifest is intentionally not a reviewer asset.

Docs Agent owns native package selection, lane, artifact, prompt, and workspace mapping. The completion command downloads its validator from the independent immutable `DOCS_AGENT_COMPLETION_CONTRACT_REVISION`; native package descriptors remain pinned by `DOCS_AGENT_PACKAGE_REVISION`. Execution, credentials, AI provider selection, sandboxing, and publication are runner-owned concerns outside this repository.

Portable recipe fields include `docsAgent`, `runner.contextRepositories`, `runner.bootstrapContract`, `runner.sourceDelta`, `runner.writablePaths`, caller-owned `runner.validationDependencies`, artifacts, verification commands, drift checks, and review output mapping suggestions.

## Strengthened Success Semantics

A live run succeeds only when four independent layers pass:

1. Agents API completes the selected native package through WP Codebox.
2. Docs Agent generates `docs-agent/completion-report/v1` from validated caller inputs, actual host workspace changes, writable paths, and bootstrap filesystem checks.
3. Caller dependency, verification, and drift commands pass.
4. WP Codebox publishes and verifies a pull request when the outcome and caller policy require one.

For `changes`, `changed_paths` exactly equals the Git diff and every path must be writable. A write-tool call that leaves no byte diff produces `no_changes`.

For every maintenance outcome, `source_delta` must be non-empty and caller-bounded: each item has a unique ID and non-empty source refs, and those records are copied canonically into the report. Caller-known drift marked `requires_documentation_change: true` requires an actual Markdown or MDX diff. Bootstrap uses `inventory` with its required positive bootstrap contract and deterministic substantive filesystem checks. Model final prose is never parsed or used for completion.

The validator writes canonical report bytes under the excluded `.codebox` artifact root, so honest no-change runs remain clean and do not trigger publication. The command artifact declaration lets WP Codebox expose those deterministic bytes to reviewers; a runtime declaration or private transcript alone never satisfies semantic completion.

## Pull Request Behavior

Docs Agent opens or updates one canonical PR for the configured branch.

- If the selected maintenance surface is current, the run succeeds only with a non-empty caller-bounded source delta and no workspace diff.
- If maintenance is needed, changes are written only under `writable_paths`.
- If the canonical PR is already open, later runs reuse the same `docs_branch` and PR instead of creating duplicates.
- `validation_dependencies` is an optional caller-owned reusable-workflow input. It is passed through the portable recipe and runs before verification commands when a live runner execution needs setup.
- `job_status`, `transcript_summary`, `credential_mode`, `success_requires_pr`, `validation_dependencies`, and bounded `projected_outputs_json` are exposed as reusable workflow outputs. A `run_agent: false` call returns `job_status: skipped`; a `dry_run: true` call validates without starting a model run. `OPENAI_API_KEY` is only required for a live OpenAI run and is never included in recipes, workflow outputs, or artifacts. Bootstrap lanes require a published pull request and its projected URL for success. Maintenance lanes allow a bounded no-change result by default; set `require_pr: true` when an acceptance or remediation run must fail without a valid target-repository PR. Runtime typed artifact declarations remain optional, while the completion post-command artifact is mandatory and exposed through WP Codebox declared-artifact staging. Raw engine data is not exposed as a workflow output or uploaded reviewer artifact.

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

These five `.agent.json` files are the complete executable package surface. Each package is the sole executable instruction authority for its lane. The reusable workflow maps `audience` and `run_kind` to exactly one package and its canonical agent slug; it does not select a separate manifest, flow, pipeline, or memory envelope. Every descriptor uses the package-source revision `a39d9db230eb9e0b72ed84465f4d61bd8dda1bab`, independently of the revision that invokes the reusable workflow, and supplies a byte-level `sha256-bytes-v1` digest.

Package updates advance the package-source revision and all five declared digests atomically. The immutable-source validator reads each package blob from that Git revision, recomputes its digest and canonical slug, and rejects a descriptor that does not match those historical bytes.

All five packages support direct import through `wp_agent_import_runtime_bundles()` and retain the source-grounded workspace-only editing boundary. They intentionally have no required write-tool gate: deterministic report/diff postconditions reject no-op writes while allowing honest no-change maintenance.

### Compatibility Impact

Direct consumers of the removed legacy `manifest.json`, `flows/`, `pipelines/`, or memory envelopes must migrate to the corresponding native `.agent.json` package listed above. Data Machine is not restored. Existing `maintain-docs.yml` consumers keep the same native architecture, but every maintenance caller must now provide a non-empty caller-bounded `source_delta`; each report item must cover that item's source refs with evidence. Bootstrap callers may instead use inventory mode and must provide positive `bootstrap_contract` criteria. `context_repositories` remains additive.

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
php tests/validate-docs-agent-completion-contract.php
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

It imports every native package through `wp_agent_import_runtime_bundles()`, verifies registration and no-change-capable tool rules, and invokes the default native chat handler far enough to resolve each registered agent. It intentionally fails when `AGENTS_API_DIR` is unavailable rather than treating an unexecuted importer as a passing test. It does not execute a model turn because the packages intentionally leave provider/model selection to the caller.

The `Docs Agent Tests` GitHub Actions workflow runs on pull requests and pushes. It fetches Docs Agent history so it can run the immutable native package source validator, then runs the structural package validator, docs-link repair smoke test, and native importer integration test against `Automattic/agents-api` at `78e2dd409010f98fa4d26cdd72572117384ab18d`, the merged commit from [Agents API #428](https://github.com/Automattic/agents-api/pull/428).
