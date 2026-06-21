# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `context_repositories`, `verification_commands`, `drift_checks`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It uses the same Docs Agent runner recipe as `maintain-docs.yml` and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use a canonical branch such as `docs-agent/build-with-wordpress-skills`, skill/package writable paths, read-only context repositories, verification commands, and drift checks through the canonical runner API.

When context repositories, verification commands, or drift checks are needed, pass them through the reusable workflow inputs above. Do not add checkout, allowed-repository, or workspace-policy plumbing to consumer workflows; the canonical runner owns those details.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` forwards those declarations through the Homeboy Extensions `expected_artifacts` and `artifact_declarations` inputs, exposes the declaration objects through `declared_artifacts_json`, and keeps the existing transcript artifact upload and runtime output projections during the migration.

The target repository needs a token path that can inspect source, write the configured paths, push the canonical branch, and open or update the pull request.

## Docs Agent Runner Recipe

Docs Agent workflow call sites use the generic Homeboy full-run workflow: `Extra-Chill/homeboy-extensions/.github/workflows/runtime-agent-full-run.yml@main`. Docs Agent keeps its runtime stack behind the committed `docs-agent/datamachine-agent-ci` recipe in `ci/docs-agent-runner-recipe.json`. Workflows run `ci/resolve-docs-agent-runner-recipe.php` and pass the resolved compatibility fields to Homeboy Extensions: `runtime_provider`, `runtime_ref`, `runtime_profile`, `runtime_profiles`, `runtime_dependencies`, `runtime_components`, `runtime_mounts`, `required_abilities`, and `runtime_config`. This migration is tracked in Automattic/docs-agent#100 and depends on the generic full-run support available on `Extra-Chill/homeboy-extensions@main`.

Data Machine remains present only inside the selected recipe for the current Docs Agent bundles, which still execute through `datamachine/run-agent-bundle`. Bundle selection is expressed through `runtime_execution` rather than legacy `bundle_path` inputs. The workspace policy mu-plugin mount and required ability checks are centralized in the recipe until Homeboy Extensions exposes a first-class recipe input.

When updating the reusable workflow ref, advance `uses: Extra-Chill/homeboy-extensions/.github/workflows/runtime-agent-full-run.yml@...` and `homeboy_extensions_ref` together, then run `php tests/validate-docs-agent-bundle.php` so workflow routing and runner config stay aligned.
