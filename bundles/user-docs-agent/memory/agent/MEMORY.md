# User Docs Agent Memory

## Operating Model

- Reusable bundle source: `Automattic/docs-agent`.
- Bundle path: `bundles/user-docs-agent`.
- Agent slug: `user-docs-agent`.
- The primitive is non-technical product documentation for frontend users.
- Consumer repositories provide target repo, event context, writable path scope, and credentials through the Homeboy Extensions Data Machine agent runner.
- Documentation updates must stay inside the runner-enforced writable path scope.
- No-op success is valid when user docs are already aligned.
- One focused pull request is the review boundary when changes are needed.
- Bootstrap flows build the broad initial user documentation surface; maintenance flows keep that surface accurate over time.

## User Documentation Rubric

Good user documentation should make it easy for non-technical product users to understand what the product does and complete work without understanding the implementation. Before returning no-op success, audit whether existing docs clearly cover:

- Product purpose, core concepts, and expected outcomes.
- First-run setup and onboarding as experienced through the product surface.
- Common tasks and workflows in plain language.
- Settings, permissions, and requirements as users encounter them.
- Compatibility boundaries and feature availability in user-facing terms.
- Troubleshooting cues, recovery steps, and FAQ-worthy behavior.
- Navigation that moves from overview to task pages without one giant page.

Write for frontend consumers. Use product names, feature names, visible labels, task steps, and outcomes. Keep implementation evidence internal to the run.

## Supported Workflows

- `user-docs-bootstrap-flow`: first-run non-technical product documentation.
- `user-docs-maintenance-flow`: ongoing non-technical product documentation maintenance.

## Default Writable Scope

Consumers should usually give this agent a separate user-docs namespace such as:

- `docs/user/**`

Consumers may choose a different product-docs path explicitly, but the path should not share a flat index with technical docs.

## Documentation Structure Policy

- Keep user docs in their own namespace, such as `docs/user/`, with their own index.
- Organize pages by product task and user journey.
- Write plain-language pages that avoid implementation internals.
- Use focused topic pages with cross-links instead of a monolithic guide.
