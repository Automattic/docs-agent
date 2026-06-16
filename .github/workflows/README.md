# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `context_repositories`, `verification_commands`, `drift_checks`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It exposes lower-level runner details and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use a canonical branch such as `docs-agent/build-with-wordpress-skills`, skill/package writable paths, read-only context repositories, verification commands, and drift checks through the canonical runner API.

When context repositories, verification commands, or drift checks are needed, pass them through the reusable workflow inputs above. Do not add checkout, allowed-repository, or workspace-policy plumbing to consumer workflows; the canonical runner owns those details.

The reusable workflow declares the expected typed review artifacts for Docs Agent runs: transcript, change summary, verification report, drift report, and workspace publication links. `maintain-docs.yml` forwards those declarations through the Homeboy Extensions `expected_artifacts` and `artifact_declarations` inputs, exposes the declaration objects through `declared_artifacts_json`, and keeps the existing transcript artifact upload and engine-data outputs during the migration.

The target repository needs a token path that can inspect source, write the configured paths, push the canonical branch, and open or update the pull request.

## Runtime-Neutral Input Migration

Docs Agent is waiting on Homeboy Extensions runtime-neutral Data Machine agent CI inputs before changing workflow call sites. Track the upstream work in Extra-Chill/homeboy-extensions#1430 and the Docs Agent migration in Automattic/docs-agent#100.

Until the pinned Homeboy Extensions workflow accepts aliases such as `agent_runtime`, `agent_runtime_ref` or `runtime_ref`, and `runtime_mounts`, keep the existing `wp_codebox_ref` and `extra_wp_codebox_mounts` workflow call inputs. The pinned workflow also still exposes `wp_codebox_wordpress_version` for callers that need to override its default. Passing only the proposed aliases to the current pinned reusable workflow would fail GitHub Actions input validation before the runner starts.

Migration patch plan after the upstream aliases land:

1. Advance the Homeboy Extensions workflow pin and matching `homeboy_extensions_ref` together.
2. Add `agent_runtime: wp-codebox` and replace `wp_codebox_ref` with the accepted runtime ref alias.
3. Replace `extra_wp_codebox_mounts` with the accepted runtime mounts alias.
4. Keep `runner_workspace`, typed artifacts, context repositories, verification commands, drift checks, and writable-path boundaries unchanged unless the upstream contract changes.
5. Update validation expectations and examples in the same patch as the workflow input rename.
