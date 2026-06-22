# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `context_repositories`, `verification_commands`, `drift_checks`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It uses the same Docs Agent runner recipe as `maintain-docs.yml` and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use a canonical branch such as `docs-agent/build-with-wordpress-skills`, skill/package writable paths, read-only context repositories, verification commands, and drift checks through the canonical runner API.

When context repositories, verification commands, or drift checks are needed, pass them through the reusable workflow inputs above. The reusable workflow sends those inputs to the canonical runner and keeps the target repository as the writable Docs Agent workspace.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` writes those declarations into the public recipe, exposes the declaration objects through `declared_artifacts_json`, and relies on WP Codebox recipe output mappings for review.

The target repository needs a token path that can inspect source, write the configured paths, push the canonical branch, and open or update the pull request.

## Docs Agent Runner Recipe

Docs Agent workflow call sites use the public Codebox reusable workflow at `Automattic/wp-codebox/.github/workflows/run-agent-task.yml@main`. Docs Agent owns the Docs Agent-specific bundle, lane, artifact, prompt, and workspace mapping before calling that generic task workflow. Consumers depend on Docs Agent inputs and review artifacts, not Homeboy Extensions internals. This migration is tracked in Automattic/docs-agent#100.

The public Codebox workflow must provide a recipe-first boundary for Docs Agent bundle selection, workspace publication, artifact declarations, verification, drift checks, and output mappings. Runtime substrate checkout resolution is intentionally outside the Docs Agent-facing recipe, and validation blocks direct Homeboy Extensions workflow calls, runtime ability names, component paths, mount directives, and define directives from consumer examples.

Bundle selection is expressed through recipe `docsAgent` fields rather than legacy workflow bundle inputs. Workspace boundaries are expressed through recipe `runner.workspace` and `runner.writablePaths` fields so agents remain workspace editors while Codebox owns sandbox execution and publication handoff.

Run `php tests/validate-docs-agent-bundle.php` after workflow changes so workflow routing and runner config stay aligned.
