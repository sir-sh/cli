# sir.sh - Local-First Workflow Runner

A powerful CLI tool for developers who love automation. **sir.sh** (CLI command: `sir`) is a local-first workflow/task runner built on Laravel Zero, designed to help you define, share, and execute complex development workflows.

## Features

- **Layered Configuration**: Supports `.sir/` folders at project and global levels with automatic layer resolution
- **Pack System**: Install workflow packs from Git repositories without Composer
- **Workflow Engine**: Define workflows in YAML with support for variables, templating, and step dependencies
- **Built-in Tasks**: Common operations like git clone, file operations, test running, and more
- **Method System**: Extensible method definitions from packs (shell or exec-based)
- **AI Agent Interface**: MCP-style tool surface for AI assistants to discover and execute workflows safely
- **Clean Output**: Step headers, progress indicators, timing, and success/fail markers

## Known Limitations (v1)

While the v1 implementation is fully functional for its intended use cases, there are some known limitations to be aware of:

1. **Template Parsing**: The `default()` function in templates doesn't handle commas or nested parentheses in default values. For v1, use simple string literals as defaults.

2. **Input Gathering**: The RunWorkflowCommand assumes workflow inputs are passed as options or the first positional argument. Multiple positional inputs aren't fully supported - use options for complex workflows.

3. **Nested Properties**: Template expressions like `{{obj.property}}` aren't supported. Access nested values by setting them as top-level variables in workflow steps.

These limitations are documented and can be addressed in future versions without breaking existing workflows.

## Installation

### Requirements

- PHP 8.2 or higher
- Git
- Composer

### Setup

```bash
# Clone the repository
git clone https://github.com/sir-sh/sir-cli.git
cd sir-cli

# Install dependencies
composer install

# Make the application executable
chmod +x application

# Optional: Create a symlink for global access
sudo ln -s $(pwd)/application /usr/local/bin/sir

# Or add to your PATH
export PATH="$PATH:$(pwd)"
```

## Quick Start

### 1. Initialize Global Configuration

```bash
# Create global .sir directory
mkdir -p ~/.sir/workflows
mkdir -p ~/.sir/packs

# Copy example workflow and config
cp examples/workflows/review.yml ~/.sir/workflows/
cp examples/config.yml ~/.sir/
```

### 2. List Available Commands

```bash
sir list
```

### 3. Create Your First Workflow

Create `.sir/workflows/hello.yml` in your home directory:

```yaml
description: "A simple hello world workflow"

inputs:
  name:
    type: string
    required: true
    description: "Your name"

steps:
  - shell: "echo 'Hello, {{name}}!'"
```

### 4. Run the Workflow

```bash
sir run hello
# When prompted, enter your name
```

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
3. Stops at `.git` directory (configurable)
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

## Example: Review Workflow

The included review workflow demonstrates a complete workflow:

```bash
sir run review myproject --target=feature/new-feature --base=main
```

This workflow:
1. Creates a temporary workspace
2. Clones the project repository
3. Runs tests automatically
4. Requests CodeRabbit review
5. Saves results to markdown files

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
