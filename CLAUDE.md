# Sir — AI-Powered Task Runner

**Version**: 0.9.x
**Status**: Feature-complete, active development

---

## What This Is

Sir is a CLI task runner that uses AI to decompose high-level instructions into executable workflows. It reads a workflow YAML file, resolves layers and dependencies, and executes tasks using built-in commands or custom packs.

---

## Project Structure

```
Sir/                     # sir-cli git repo (https://github.com/ulties/sir-cli)
├── app/                # Laravel Zero CLI application
│   ├── Commands/        # Sir command classes
│   ├── Providers/       # Service providers
│   └── Services/        # Core services (Methods, Packs, Workflow)
├── bootstrap/          # Laravel Zero bootstrap
├── config/             # Laravel config files
├── docs/               # Development documentation
├── examples/           # Example workflows and packs
├── specs/              # Spec-Kit specifications (15 specs)
├── tests/              # PHPUnit tests
├── website/            # Marketing site (separate git repo)
├── box.json            # PHAR builder config
├── composer.json
└── CLAUDE.md
```

---

## GitHub Repos

| Repo | URL |
|---|---|
| `ulties/sir-cli` | https://github.com/ulties/sir-cli |
| `ulties/sir-website` | https://github.com/ulties/sir-website |

---

## Specs (specs/)

15 spec directories covering: layer resolution, configuration loading, workflow engine, template system, pack system, method system, built-in tasks, AI agent interface, CLI commands, conditional logic, loop constructs, nested properties, pack updates, remote execution, workflow scheduling.

---

## Daily Workflow

```bash
# Run Sir
./sir run workflow.yml

# Install a pack
./sir pack install <name>

# List available commands
./sir list
```
