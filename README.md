# Docs Agent

Reusable Data Machine source-code documentation agent bundle.

`docs-agent` is intentionally a portable bundle, not a repo-specific workflow. Consumer repositories run it through the Homeboy Extensions Data Machine agent runner and declare which documentation workflow they want. Both workflows generate documentation from source code.

## Bundle

- Bundle path: `bundles/docs-agent`
- Agent slug: `docs-agent`
- Technical pipeline slug: `technical-docs-pipeline`
- Technical flow slug: `technical-docs-flow`
- User-facing pipeline slug: `user-docs-pipeline`
- User-facing flow slug: `user-docs-flow`

## Runner Contract

Consumers should pass the generic runner a config equivalent to `examples/homeboy-runner-config.example.json`.

Important fields:

- `bundle_repo`: `https://github.com/Automattic/docs-agent.git`
- `bundle_ref`: a branch, tag, or SHA from this repo
- `bundle_path_in_repo`: `bundles/docs-agent`
- `pipeline_slug` and `flow_slug`: selected by the consuming repo
- `success_requires_pr`: `false`
- `tool_recorders[].forced_parameters.allowed_file_paths`: the hard writable path scope, for example `README.md` and `docs/**`

The runner should treat both outcomes as successful:

- `pr_opened`: docs changes were needed and a pull request was opened.
- `no_changes`: docs are already aligned and no pull request was needed.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
```
