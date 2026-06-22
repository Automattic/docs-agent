# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `context_repositories`, `verification_commands`, `drift_checks`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It uses the same Docs Agent runner recipe as `maintain-docs.yml` and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use a canonical branch such as `docs-agent/build-with-wordpress-skills`, skill/package writable paths, read-only context repositories, verification commands, and drift checks through the canonical runner API.

When context repositories, verification commands, or drift checks are needed, pass them through the reusable workflow inputs above. The reusable workflow sends those inputs to the canonical runner and keeps the target repository as the writable Docs Agent workspace.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` forwards those declarations through runner artifact inputs, exposes the declaration objects through `declared_artifacts_json`, and publishes transcript artifacts and runtime output projections for review.

The target repository needs a token path that can inspect source, write the configured paths, push the canonical branch, and open or update the pull request.

## Docs Agent Runner Recipe

Docs Agent workflow call sites should use a public Codebox reusable workflow that accepts the committed `docs-agent/codebox-homeboy-runner` recipe in `ci/docs-agent-runner-recipe.json`. Workflows run `ci/resolve-docs-agent-runner-recipe.php` to keep runner setup centralized while consumers depend on Docs Agent inputs and review artifacts, not Homeboy Extensions internals. This migration is tracked in Automattic/docs-agent#100.

TODO(Codebox public workflow): Codebox does not yet expose the stable public reusable workflow needed to replace the current direct call to `Extra-Chill/homeboy-extensions/.github/workflows/runtime-agent-full-run.yml@main`. Until that wrapper exists, the Homeboy Extensions workflow path and resolver-emitted runtime fields are transitional wiring only. Runtime substrate checkout resolution is intentionally outside the Docs Agent-facing recipe, and validation blocks direct runtime ability names, component paths, mount directives, and define directives from consumer examples.

Bundle selection is expressed through `runtime_execution` rather than legacy `bundle_path` inputs. Workspace boundaries are expressed through `runner_workspace`, `writable_paths`, and runner-owned publication inputs so agents remain workspace editors while Homeboy owns branch and pull request publication.

When Codebox exposes the public reusable workflow, replace the direct Homeboy Extensions workflow call sites and then run `php tests/validate-docs-agent-bundle.php` so workflow routing and runner config stay aligned.
