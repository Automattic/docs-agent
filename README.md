# Docs Agent

Reusable Data Machine documentation maintenance agent bundle.

`docs-agent` is intentionally a portable bundle, not a repo-specific workflow. Consumer repositories run it through the Homeboy Extensions Data Machine agent runner and pass the target repository, event context, writable docs paths, provider credentials, and dependency checkouts.

## Bundle

- Bundle path: `bundles/docs-agent`
- Agent slug: `docs-agent`
- Pipeline slug: `docs-agent-pipeline`
- Flow slug: `docs-maintenance-flow`

## Runner Contract

Consumers should pass the generic runner a config equivalent to `examples/homeboy-runner-config.example.json`.

Important fields:

- `bundle_repo`: `https://github.com/Automattic/docs-agent.git`
- `bundle_ref`: a branch, tag, or SHA from this repo
- `bundle_path_in_repo`: `bundles/docs-agent`
- `success_requires_pr`: `false`
- `tool_recorders[].forced_parameters.allowed_file_paths`: the hard writable path scope, for example `README.md` and `docs/**`

The runner should treat both outcomes as successful:

- `pr_opened`: docs changes were needed and a pull request was opened.
- `no_changes`: docs are already aligned and no pull request was needed.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
```
