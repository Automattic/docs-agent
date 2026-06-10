# Docs Agent CI

`docs-agent.yml` runs the Docs Agent bundle against any target repository passed as `target_repo`.

The target repository must have the Homeboy GitHub App installed so the workflow can authenticate, inspect source, write documentation updates, and open pull requests.

The central dispatcher supports separate lanes for technical docs, user docs, and live skills maintenance. Use skills flows such as `skills-maintenance-flow` with skills/package writable paths instead of broad docs paths.

For `Automattic/build-with-wordpress`, schedule skills upkeep separately from docs upkeep. The skills lane should use a canonical branch such as `docs-agent/build-with-wordpress-skills`, write only skill/package surfaces, and verify with `pnpm build`, `pnpm verify`, and a generated-output drift check after build.

- Reusable workflow internals: https://github.com/Extra-Chill/homeboy-extensions/pull/500
- Bundle internals: https://github.com/Automattic/docs-agent/pull/1
