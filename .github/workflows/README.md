# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `verification_commands`, `drift_checks`, `prompt`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It uses the same Docs Agent runner recipe as `maintain-docs.yml` and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

Schedule skills upkeep separately from docs upkeep. The skills lane should use a dedicated branch such as `docs-agent/skills-upkeep`, skill/package writable paths, verification commands, and drift checks through the portable recipe.

When verification commands or drift checks are needed, pass them through the reusable workflow inputs above. The reusable workflow includes those executable inputs in the portable recipe and keeps the target repository as the writable Docs Agent workspace.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` writes those declarations into the portable recipe and exposes the declaration objects through `declared_artifacts_json`.

The target repository must pass `ACCESS_TOKEN`. Docs Agent targets the calling repository, so normal runs require a token that can inspect source, write the configured paths, push the canonical branch, and open or update that repository's pull request. Same-organization callers can use `secrets: inherit` with an `ACCESS_TOKEN` secret; cross-organization callers map it explicitly. WP Codebox #1751 requires a cross-repository task token to reach every declared target repository. The native runner fails before execution when this required credential is absent.

## Docs Agent Runner Recipe

Docs Agent workflow call sites prepare a portable recipe instead of calling a concrete runner. Docs Agent owns the Docs Agent-specific bundle, lane, artifact, prompt, and workspace mapping. Consumers depend on Docs Agent inputs and review artifacts, not runner internals.

The recipe boundary covers Docs Agent bundle selection, workspace publication expectations, artifact declarations, verification, drift checks, and output mapping suggestions. Runtime substrate checkout resolution is intentionally outside the Docs Agent-facing recipe, and validation blocks concrete runner workflow calls, runtime ability names, component paths, mount directives, provider defaults, and define directives from consumer examples.

Bundle selection is expressed through recipe `docsAgent` fields. Workspace boundaries are expressed through recipe `runner` fields so agents remain workspace editors while caller-owned execution handles sandboxing and publication handoff.

Run `php tests/validate-docs-agent-bundle.php` after workflow changes so workflow routing and runner config stay aligned.
