# Docs Agent

Reusable Data Machine source-code documentation agent bundle.

`docs-agent` lets teams run documentation automation against a GitHub repository without building a bespoke agent for that repository. The target repo can be a WordPress plugin, package, service, app, CLI, or other codebase. The automation is repo-agnostic as long as the runner can read the target GitHub repo, write to the configured documentation paths, and open a pull request.

## What It Does

Docs Agent imports a reusable agent bundle into a temporary WordPress/Data Machine runtime, points it at a target GitHub repository, lets the agent inspect source code and existing docs, and opens one documentation PR when updates are needed.

It currently ships two workflows:

- **Technical documentation**: developer-facing docs for APIs, architecture, extension points, lifecycle, data contracts, examples, operational workflows, and project philosophy.
- **User documentation**: non-technical docs for getting started, common tasks, configuration, and troubleshooting.

Both workflows may finish with `no_changes` when the existing docs already cover the source accurately.

## How The Pieces Fit

```text
Target GitHub repo
        |
        | read files, write docs, open PR
        v
Data Machine Code GitHub tools
        |
        | tools exposed to the agent
        v
Data Machine flow + pipeline
        |
        | agent loop and job execution
        v
Agents API + model provider
        |
        | reusable runtime primitives + LLM call
        v
Docs Agent bundle
```

The consumer repo does not need to contain this bundle. It only needs a Homeboy runner configuration that says which bundle, flow, target repo, credentials, and writable paths to use.

## Repositories Involved

- Docs Agent bundle: https://github.com/Automattic/docs-agent
- Agents API runtime primitives: https://github.com/Automattic/agents-api
- Data Machine agent, bundle, flow, and pipeline runtime: https://github.com/Extra-Chill/data-machine
- Data Machine Code GitHub tools and PR-writing abilities: https://github.com/Extra-Chill/data-machine-code
- OpenAI provider for the WordPress AI Client: https://github.com/WordPress/ai-provider-for-openai
- Homeboy command runner and artifact framework: https://github.com/Extra-Chill/homeboy
- Homeboy WordPress/Playground extension layer: https://github.com/Extra-Chill/homeboy-extensions
- WordPress Playground runtime used by Homeboy WordPress runs: https://github.com/WordPress/wordpress-playground

## Bundle Contents

- Bundle path: `bundles/docs-agent`
- Agent slug: `docs-agent`
- Technical pipeline slug: `technical-docs-pipeline`
- Technical flow slug: `technical-docs-flow`
- User-facing pipeline slug: `user-docs-pipeline`
- User-facing flow slug: `user-docs-flow`

## Setup For A Consumer Repo

Docs Agent is not Automattic-only. Anyone can use it if they can run the Homeboy/Data Machine runner stack and provide the required GitHub and model-provider credentials. Automattic repositories can use the same contract through internal CI conventions, but the bundle itself does not depend on Automattic-specific source code.

### 1. Decide Which Docs The Agent May Edit

Set a narrow writable scope before enabling the workflow. The example allows only:

- `README.md`
- `docs/**`

Keep this scope small unless the repository intentionally stores documentation elsewhere.

### 2. Create Credentials

The runner needs:

- `GITHUB_TOKEN`: token with access to read the target repo, create branches, write configured docs files, and open pull requests.
- `OPENAI_API_KEY`: model provider credential used by `ai-provider-for-openai`.

Use repository or organization secrets in CI. Do not commit credentials into the runner config.

### 3. Add A Runner Config

Start from `examples/homeboy-runner-config.example.json` and change these fields:

- `component_id`: stable ID for the consuming repo or CI driver.
- `component_path`: checkout path for the consuming repo during the run.
- `validation_dependencies`: local paths to prepared checkouts for Agents API, Data Machine, Data Machine Code, and the OpenAI provider.
- `bundle_ref`: pinned branch, tag, or SHA from this repo.
- `pipeline_slug` and `flow_slug`: choose either the technical or user workflow.
- `target_repo`: GitHub `OWNER/REPO` for the repository being documented.
- `allowed_repos`: usually the same single `OWNER/REPO`.
- `tool_recorders[].forced_parameters.allowed_file_paths`: exact docs path scope the agent may write.
- `bench_env`: maps CI secrets into the runner environment.

For technical docs, use:

```json
{
  "pipeline_slug": "technical-docs-pipeline",
  "flow_slug": "technical-docs-flow"
}
```

For user docs, use:

```json
{
  "pipeline_slug": "user-docs-pipeline",
  "flow_slug": "user-docs-flow"
}
```

### 4. Wire It Into Homeboy

Run the config through the Homeboy WordPress/Data Machine agent runner used by your repo's CI. The runner is responsible for creating the temporary WordPress runtime, mounting the validation dependencies, importing `bundles/docs-agent`, executing the selected flow, recording artifacts, and reporting whether the result was `pr_opened` or `no_changes`.

The important contract is the config shape, not the target repo's language. If the target repo is available through GitHub and the allowed docs paths are writable, Docs Agent can operate on it.

### 5. Review The Output PR

Docs Agent opens a documentation PR only when it changes files. Review it like any other generated change:

- Check that the docs match the current source.
- Confirm the agent stayed inside the intended writable paths.
- Confirm the PR scope is one coherent docs update, not unrelated cleanup.
- Edit, close, or merge based on normal repository review standards.

## Runner Contract

Consumers should pass the generic runner a config equivalent to `examples/homeboy-runner-config.example.json`.

Important fields:

- `bundle_repo`: `https://github.com/Automattic/docs-agent.git`
- `bundle_ref`: a branch, tag, or SHA from this repo
- `bundle_path_in_repo`: `bundles/docs-agent`
- `agent_slug`: `docs-agent`
- `pipeline_slug` and `flow_slug`: selected by the consuming repo
- `target_repo` and `allowed_repos`: GitHub repository scope
- `success_requires_pr`: `false`
- `tool_recorders[].forced_parameters.allowed_file_paths`: hard writable path scope, for example `README.md` and `docs/**`

The runner should treat both outcomes as successful:

- `pr_opened`: docs changes were needed and a pull request was opened.
- `no_changes`: docs are already aligned and no pull request was needed.

## Technical Documentation Standard

The technical workflow should optimize for a clean, navigable documentation surface that helps developers work on, use, and extend the target codebase. It should not treat the presence of a README as sufficient by itself.

Before returning `no_changes`, the agent should audit whether existing docs cover:

- Public APIs, exported interfaces, commands, abilities, hooks, filters, events, and configuration.
- Internal processes, lifecycle, scheduling, queues, workflows, state transitions, and failure handling.
- Architecture boundaries, module relationships, data flow, and how the important pieces connect.
- Data contracts, value objects, storage, persistence, auth, permissions, and compatibility boundaries.
- Build, test, release, local development, and operational workflows.
- Practical examples for common integration, contribution, and extension paths.
- Software philosophy, ownership boundaries, constraints, and design principles that guide contributors.

When coverage is missing, stale, fragmented, or too shallow for a developer to confidently maintain or extend the project, the technical workflow should update docs and open one reviewable pull request.

## User Documentation Standard

The user-facing workflow should explain what users can do with the project without requiring them to understand internal implementation details. It should cover setup, common tasks, configuration that affects behavior, expected outputs, and troubleshooting.

When the source exposes user-visible behavior that is undocumented or stale, the user workflow should update the docs and open one reviewable pull request.

## Portability Notes

- Docs Agent is portable across GitHub repositories, not tied to one codebase.
- The target repo does not need to be a WordPress project.
- The runner runtime is WordPress/Data Machine-based, so the CI environment must provide the WordPress/Data Machine dependencies listed above.
- The writable path scope is the main safety boundary. Keep it explicit and narrow.
- `success_requires_pr` should stay `false` because a correct run may find that no docs changes are needed.

## Validation

```bash
php tests/validate-docs-agent-bundle.php
```
