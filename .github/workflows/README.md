# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `context_profile`, `context_repositories`, `verification_commands`, `drift_checks`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It exposes lower-level runner details and should not be the starting point for consumer repositories.

The consumer workflow supports separate lanes for technical docs, user docs, and live skills maintenance. Use `audience: skills` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use `context_profile: studio-wordpress-skills`, a canonical branch such as `docs-agent/build-with-wordpress-skills`, and skill/package writable paths. The profile passes read-only `studio` and `wordpress-agent-skills` context aliases plus `pnpm build`, `pnpm verify`, and a generated-output drift check through the canonical runner API.

When context repositories, verification commands, or drift checks are needed, pass them through the reusable workflow inputs above. Do not add checkout, allowed-repository, or workspace-policy plumbing to consumer workflows; the canonical runner owns those details.

The target repository needs a token path that can inspect source, write the configured paths, push the canonical branch, and open or update the pull request.
