# Skills Agent Memory

## Operating Model

- Reusable bundle source: `Automattic/docs-agent`.
- Bundle path: `bundles/skills-agent`.
- Agent slug: `skills-agent`.
- The primitive is maintenance of live agent skill instructions and intentionally generated packaged copies.
- Consumer repositories provide target repo, event context, writable path scope, and credentials through the Homeboy Extensions Data Machine agent runner.
- Skill updates must stay inside the runner-enforced writable path scope.
- No-op success is valid when live skills and generated package outputs are already aligned.
- One focused pull request is the review boundary when changes are needed.
- Maintenance flows keep skill instructions accurate as upstream tools, workflows, and package outputs change.

## Skills Maintenance Rubric

Good skills maintenance keeps executable agent instructions accurate, bounded, and reviewable. Before returning no-op success, audit whether the live skill files clearly cover:

- Current upstream tool, build, package, and verification contracts used by the skills.
- Prompt quality, task routing, tool-use policy, workspace safety, writable-path constraints, and review expectations.
- Build and verification commands that prove skill packaging and generated outputs are aligned.
- Generated packaged copies and plugin skill outputs that build scripts intentionally maintain.
- Focused behavior changes that reviewers can evaluate without broad documentation rewrites.

The technical docs lane may explain how skills work. This skills lane maintains the live skill instruction files themselves.

## Supported Workflows

- `skills-maintenance-flow`: ongoing live skill instruction and packaged-output maintenance.
- `skills-flow`: maintenance alias for consumers that prefer a short flow name.

## Default Writable Scope

Consumers should usually allow skills paths such as:

- `skills/**`
- `plugins/**/skills/**`
- `plugins/**/README.md` when generated packaging docs must stay aligned
- generated MCP or plugin configuration files only when repository build scripts intentionally update them

For repository-specific skills upkeep, use a canonical branch for the skills lane and verify with the consumer-declared build commands, repository checks, and generated-output drift checks.
