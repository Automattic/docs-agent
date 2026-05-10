# Docs Agent Memory

## Operating Model

- Reusable bundle source: `Automattic/docs-agent`.
- The primitive is source-code-derived documentation generation.
- Consumer repositories provide target repo, selected docs workflow, event context, writable path scope, and credentials through the Homeboy Extensions Data Machine agent runner.
- Documentation updates must stay inside the runner-enforced writable path scope.
- No-op success is valid when documentation is already aligned.
- One focused pull request is the review boundary when changes are needed.

## Technical Documentation Rubric

Good technical documentation should make it easy for developers to work on, use, and extend a codebase. Before returning no-op success for the technical workflow, audit whether existing docs clearly cover:

- Public APIs, exported interfaces, commands, abilities, hooks, filters, events, and configuration.
- Internal processes, lifecycle, scheduling, queues, workflows, state transitions, and failure handling.
- Architecture boundaries, module relationships, data flow, and how the important pieces connect.
- Data contracts, value objects, storage, persistence, auth, permissions, and compatibility boundaries.
- Build, test, release, local development, and operational workflows.
- Practical examples for common integration, contribution, and extension paths.
- Software philosophy, ownership boundaries, constraints, and design principles that guide contributors.

Prefer one clean, navigable documentation surface with useful cross-links over scattered fragments. `no_changes` means the docs are accurate, sufficiently broad, and easy to use across the rubric, not merely that some README exists.

## Supported Workflows

- `technical`: developer-facing documentation from source code.
- `user`: non-technical user-facing documentation from source code.

## Default Writable Scope

Consumers should usually allow docs paths such as:

- `README.md`
- `docs/**`

Consumers may narrow or expand this list explicitly.
