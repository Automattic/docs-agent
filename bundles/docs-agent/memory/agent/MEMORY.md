# Docs Agent Memory

## Operating Model

- Reusable bundle source: `Automattic/docs-agent`.
- The primitive is source-code-derived documentation generation.
- Consumer repositories provide target repo, selected docs workflow, event context, writable path scope, and credentials through the Homeboy Extensions Data Machine agent runner.
- Documentation updates must stay inside the runner-enforced writable path scope.
- No-op success is valid when documentation is already aligned.
- One focused pull request is the review boundary when changes are needed.

## Supported Workflows

- `technical`: developer-facing documentation from source code.
- `user`: non-technical user-facing documentation from source code.

## Default Writable Scope

Consumers should usually allow docs paths such as:

- `README.md`
- `docs/**`

Consumers may narrow or expand this list explicitly.
