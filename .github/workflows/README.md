# Docs Agent CI

`docs-agent.yml` runs the Docs Agent bundle against any target repository passed as `target_repo`.

The target repository must have the Homeboy GitHub App installed so the workflow can authenticate, inspect source, write documentation updates, and open pull requests.

- Reusable workflow internals: https://github.com/Extra-Chill/homeboy-extensions/pull/500
- Bundle internals: https://github.com/Automattic/docs-agent/pull/1
