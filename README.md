# sir.sh - Local-First Workflow Runner

A powerful CLI tool for developers who love automation. **sir.sh** (CLI command: `sir`) is a local-first workflow/task runner built on Laravel Zero, designed to help you define, share, and execute complex development workflows.

## Features

- **Layered Configuration**: Supports `.sir/` folders at project and global levels with automatic layer resolution
- **Pack System**: Install workflow packs from Git repositories without Composer
- **Workflow Engine**: Define workflows in YAML with support for variables, templating, and step dependencies
- **Built-in Tasks**: Common operations like git clone, file operations, test running, and more
- **Method System**: Extensible method definitions from packs (shell or exec-based)
- **MCP Server**: Local stdio tools to list, inspect, and preview workflows
- **Clean Output**: Step headers, progress indicators, timing, and success/fail markers

## Known Limitations (v1)

While the v1 implementation is fully functional for its intended use cases, there are some known limitations to be aware of:

1. **Template Parsing**: The `default()` function in templates doesn't handle commas or nested parentheses in default values. For v1, use simple string literals as defaults.

2. **Input Gathering**: The CLI accepts positional input values and repeatable named `--input=name=value` options.

3. **Nested Properties**: Template expressions like `{{obj.property}}` aren't supported. Access nested values by setting them as top-level variables in workflow steps.

These limitations are documented and can be addressed in future versions without breaking existing workflows.

## Installation

### Requirements

- PHP 8.2 or higher
- Git
- Composer

### Setup

On Linux or macOS, clone into a directory where you can keep the checkout.

```sh
git clone https://github.com/sir-sh/cli.git
cd cli
composer install --no-dev --no-interaction
mkdir -p "$HOME/.local/bin"
ln -s "$PWD/application" "$HOME/.local/bin/sir"
export PATH="$HOME/.local/bin:$PATH"
```

### Run a local workflow

The [quickstart](https://www.sir.sh/docs/QUICKSTART) shows how to install and
run a pinned workflow from the [Sir workflow registry](https://github.com/sir-sh/workflows).
Pass `--input=name=value` for named inputs and `--dry-run` to preview the steps.

### Open the local dashboard

Run `sir start` from your project and open the private URL printed in your
terminal. Browse installed workflows, preview their steps, request a run, and
see run status and history. Run views refresh every five seconds and show
step numbers, timestamps, and outcomes. Approve queued runs in your terminal
with `sir approve <request-id>`. The dashboard records structured activity
and leaves command output and inputs out of the activity log.

Configuration lists the active `.sir` layers and their configuration files.
Connections lists configured remote target names and types without contacting
them or showing endpoints, credentials, and environment values.

The dashboard also lets you browse the Sir registry and review the YAML before
installing the reviewed revision into your project's `.sir/workflows` directory.
Installation and execution require separate actions.

Open **Ask Sir** to find workflows, open an installed workflow’s run review, or
look up a request by ID. Try `find release`, `sir please run hello`, or
`status REQUEST_ID`. The text assistant works locally and uses the same
terminal approval and registry review as the rest of the dashboard. Use
`create WORKFLOW` to open an editable YAML draft, review it, and explicitly
save it to the current project.

For optional OpenAI Realtime voice input, set `SIR_OPENAI_API_KEY` only in the
environment that launches `sir start`. Start voice input from Ask Sir, grant
microphone access, speak, then finish and review the transcript before sending
it. Microphone audio goes to OpenAI and uses your API account. Sir keeps the
API key on the local server and applies the same workflow review to transcribed
input. See [OpenAI’s WebRTC documentation](https://developers.openai.com/api/docs/guides/voice-webrtc?voice-api=realtime).

Start the read-only MCP server with
`sir mcp:serve --project-dir=/absolute/path/to/project`. See the
[MCP client setup guide](https://www.sir.sh/docs/QUICKSTART)
for tool names and verification commands.

### Pair a project with Sir Remote

On macOS or Linux, enter a fresh pairing code from the hosted dashboard in an
interactive terminal. Run the pairing and sync commands from the project you
want to share. Each local Sir profile can pair one project.

```shell
sir relay pair https://remote.example.com "Development machine"
sir relay share hello
sir relay sync --watch
```

Watch mode retries every 30 seconds while remote access is suspended or the
connection is paused. It reconnects with the same pairing when access returns.

Only explicitly shared workflow IDs and revisions, their recent approved
dashboard outcomes, and device availability are sent. To receive run
requests for a workflow without inputs, share it again with
`sir relay share hello --allow-runs` and run `sir relay sync --watch --review`
in an interactive terminal. Each request requires typing `approve` after
reviewing its local definition. Updating the workflow or its method
dependencies requires sharing the new revision explicitly.

Use `sir relay share hello --remove` and sync to remove the workflow from
the hosted view. Revoke the device in the hosted dashboard to invalidate
its credential and use `sir relay unpair` to remove the local pairing.

## Core Commands

### Workflow Execution

```bash
# Run a workflow
sir run <workflow>

# Run with dry-run (preview without executing)
sir run <workflow> --dry-run

# Run non-interactively
sir run <workflow> --non-interactive --yes
```

### Workflow Discovery

```bash
# List all workflows
sir workflows:list

# Show workflow details
sir workflows:show <workflow>

# Show execution plan
sir plan <workflow>

# Show loaded .sir layers
sir where
```

### Pack Management

```bash
# Install a pack from GitHub
sir recipes:install github:owner/repo
sir recipes:install github:owner/repo@branch

# Install with shorthand
sir recipes:install owner/repo

# Install from git URL
sir recipes:install git:https://github.com/owner/repo.git#main

# Install to project instead of global
sir recipes:install owner/repo --project

# List installed packs
sir recipes:list
sir recipes:list --project

# Remove a pack
sir recipes:remove <pack-id>
sir recipes:remove <pack-id> --project
```

### AI Agent Interface

```bash
# List available tools
sir agent tools

# Show execution plan for a workflow
sir agent plan <workflow>

# Execute with confirmation
sir agent run <workflow>
```

## Layered Configuration

Sir resolves `.sir/` folders by walking up from the current directory:

1. Current directory: `./.sir`
2. Parent directories: `../.sir`, `../../.sir`, etc.
3. Walks all the way to root `/`
4. Global: `~/.sir`

**Precedence**: Nearest layer overrides parent, parent overrides global.

```bash
# Check which layers are loaded
sir where
```

## Workflow Structure

Workflows are YAML files in `.sir/workflows/` directories:

```yaml
description: "Workflow description"

inputs:
  myInput:
    type: string
    required: true
    default: "default value"
    description: "Input description"

steps:
  # Call a built-in task
  - task: workspace.temp
    with:
      prefix: "my-workspace"
    saveAs: workspace
  
  # Call a pack method
  - method: git.clone
    with:
      repo: "https://github.com/user/repo.git"
      folder: "{{workspace.workspace}}/repo"
    saveAs: cloneResult
  
  # Run a shell command
  - shell: "echo 'Working dir: {{cloneResult.path}}'"
  
  # Use saved variables
  - task: files.write
    with:
      path: "{{workspace.workspace}}/output.txt"
      content: "Cloned to {{cloneResult.path}}"
```

### Templating

Workflows support template expressions:

- `{{variable}}` - Simple variable substitution
- `{{shellQuote(variable)}}` - Shell-quoted variable (for security)
- `{{default(variable, 'fallback')}}` - Variable with fallback

## Built-in Tasks

Sir includes several built-in tasks:

### Workspace Tasks

- `workspace.temp` - Create temporary workspace directories

### File Tasks

- `files.write` - Write content to a file
- `files.writeMd` - Write markdown with optional title

### Git Tasks

- `git.clone` - Clone a repository

### Testing Tasks

- `tests.auto` - Automatically detect and run tests (npm or composer)

### Review Tasks

- `coderabbit.review` - Request CodeRabbit review (requires CLI)

## Pack System

Packs are reusable collections of methods that can be installed from Git repositories.

### Pack Structure

```
pack-name/
├── sir-pack.json          # Pack metadata
├── methods/
│   ├── method1.json      # Method definition
│   └── method2.json
└── scripts/              # Optional scripts for exec methods
    └── script.py
```

### Method Definition

Example `methods/clone.json`:

```json
{
  "method": "clone",
  "description": "Clone a git repository",
  "args": {
    "repo": {
      "type": "string",
      "required": true
    },
    "folder": {
      "type": "path",
      "required": false
    }
  },
  "effects": {
    "network": true,
    "writes": ["{{folder}}"]
  },
  "impl": {
    "type": "shell",
    "command": "git clone {{shellQuote(repo)}} {{shellQuote(folder)}}"
  }
}
```

### Installing Example Pack

```bash
# The example pack is in examples/packs/sir-git
# To use it, install it as a pack:
cd examples/packs
git init
git add .
git commit -m "Example pack"

# Then install from local path (in real usage, you'd push to GitHub)
# For now, manually copy to ~/.sir/packs/
cp -r sir-git ~/.sir/packs/
```

## Example: Release Notes Workflow

Install the [release notes workflow from the registry](https://github.com/sir-sh/workflows/tree/main/workflows/release-notes) and pass a Git ref for the previous release.

```bash
sir run release-notes --non-interactive --input=base=v0.1.0
```

## Development

### Running Tests

```bash
./vendor/bin/pest
```

### Code Style

Check code style:

```bash
./vendor/bin/pint --test
```

Fix code style:

```bash
./vendor/bin/pint
```

### Building

Build a standalone executable:

```bash
php application app:build
```

## Architecture

Sir.sh is organized into several key components:

- **LayerResolver**: Discovers `.sir` directories
- **ConfigLoader**: Loads and merges configurations
- **PackManager**: Manages pack installation from Git
- **MethodRegistry/Executor**: Handles pack method execution
- **WorkflowLoader/Runner**: Loads and executes workflows
- **TaskRegistry**: Registry for built-in tasks
- **Context**: Manages variables across workflow execution

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This project is licensed under the MIT License.

## Credits

Built with [Laravel Zero](https://laravel-zero.com/) - The Laravel micro-framework for console applications.
