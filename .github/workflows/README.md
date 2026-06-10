# Docs Agent Workflows

`maintain-docs.yml` is the consumer-facing reusable workflow for scheduled documentation upkeep. Consumer repositories pass product-level inputs such as `audience`, `base_ref`, `docs_branch`, `writable_paths`, `prompt`, `model`, and `run_agent`.

`docs-agent.yml` is a maintainer/debug workflow for central dispatch against an arbitrary `target_repo`. It exposes lower-level runner details and should not be the starting point for consumer repositories.

The target repository needs a token path that can inspect source, write the configured documentation paths, push the canonical docs branch, and open or update the pull request.
