# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `verification_commands`, `drift_checks`, `prompt`, and `run_agent`.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

Schedule skills upkeep separately from docs upkeep. The skills lane should use a dedicated branch such as `docs-agent/skills-upkeep`, skill/package writable paths, verification commands, and drift checks through the portable recipe.

When verification commands or drift checks are needed, pass them through the reusable workflow inputs above. The reusable workflow includes those executable inputs in the portable recipe and keeps the target repository as the writable Docs Agent workspace.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` writes those declarations into the portable recipe and exposes the declaration objects through `declared_artifacts_json`.

The target repository must pass `ACCESS_TOKEN` and `EXTERNAL_PACKAGE_SOURCE_POLICY`. `ACCESS_TOKEN` authorizes target-repository publication. The policy is a separate v1 JSON secret that authorizes only the selected public standalone Docs Agent package; it is forwarded to WP Codebox as a secret and never serialized into the task descriptor. Same-organization callers can use `secrets: inherit` with both secrets; cross-organization callers map each secret explicitly.

## Docs Agent Runner Recipe

Docs Agent workflow call sites prepare a portable recipe instead of calling a concrete runner. Docs Agent owns the native package, lane, artifact, prompt, and workspace mapping. Consumers depend on Docs Agent inputs and review artifacts, not runner internals.

The recipe boundary covers standalone native package selection, workspace publication expectations, artifact declarations, verification, drift checks, and output mapping suggestions. Package provenance is the immutable reusable-workflow commit and each descriptor includes a byte digest. Runtime substrate checkout resolution is intentionally outside the Docs Agent-facing recipe, and validation blocks concrete runner workflow calls, runtime ability names, component paths, mount directives, provider defaults, and define directives from consumer examples.

Native package selection is expressed through recipe `docsAgent.externalPackageSource`. Workspace boundaries are expressed through recipe `runner` fields so agents remain workspace editors while caller-owned execution handles sandboxing and publication handoff.

Run `php tests/validate-docs-agent-bundle.php` after workflow changes so workflow routing and runner config stay aligned.
