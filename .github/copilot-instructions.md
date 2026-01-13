# GitHub Copilot Instructions for sir-cli

## Project Overview

sir.sh is a local-first workflow/task runner CLI tool built on Laravel Zero. It helps developers define, share, and execute complex development workflows with a layered configuration system, pack management, and AI agent interface.

## Technology Stack

- **Framework**: Laravel Zero (v12.0+)
- **Language**: PHP 8.2+
- **Testing**: Pest (v3.8+/v4.1+)
- **Code Style**: Laravel Pint
- **Configuration**: YAML (Symfony YAML component)

## Code Style & Standards

### PHP Standards

- Follow PSR-12 coding standards
- Use Laravel Pint for code formatting: `./vendor/bin/pint`
- Run Pint in test mode before committing: `./vendor/bin/pint --test`
- Use strict types: `declare(strict_types=1);`
- Use type hints for all parameters and return types
- Prefer named arguments for clarity when calling methods with many parameters

### Testing Requirements

- **All changes must include tests** - patches without tests will not be accepted
- Run tests with: `./vendor/bin/pest`
- Tests are organized in `tests/Feature/` and `tests/Unit/`
- Use Pest's fluent assertion syntax
- Test both success and failure scenarios
- Mock external dependencies (filesystem, git operations, network calls)

### Documentation Requirements

- Update README.md for user-facing changes
- Document any behavior changes in relevant files
- Use PHPDoc blocks for classes and public methods
- Include example usage in docblocks for complex methods

## Architecture & Key Components

### Core Services (app/Services/)

1. **LayerResolver**: Discovers `.sir` directories by walking up from current directory to root, then global `~/.sir`
2. **ConfigLoader**: Loads and merges configurations from multiple layers (nearest overrides parent)
3. **PackManager**: Manages pack installation from Git repositories
4. **MethodRegistry/Executor**: Handles pack method execution
5. **WorkflowLoader/Runner**: Loads and executes YAML workflows
6. **TaskRegistry**: Registry for built-in tasks
7. **Context**: Manages variables across workflow execution with template support

### Template System

- Supports `{{variable}}` for variable substitution
- `{{shellQuote(variable)}}` for shell-safe quoting
- `{{default(variable, 'fallback')}}` for defaults (v1: simple literals only, no commas/nested parentheses)

### Known v1 Limitations

1. **Template Parsing**: `default()` function doesn't handle commas or nested parentheses in default values
2. **Input Gathering**: Multiple positional inputs not fully supported - use options for complex workflows
3. **Nested Properties**: `{{obj.property}}` not supported - set nested values as top-level variables

## Development Workflow

### Before Making Changes

1. Run existing tests to check for pre-existing issues: `./vendor/bin/pest`
2. Check code style: `./vendor/bin/pint --test`
3. Understand the layered configuration system and workflow execution flow

### Making Changes

1. Write tests first (TDD approach preferred)
2. Make minimal, focused changes
3. Update documentation if behavior changes
4. Run tests frequently: `./vendor/bin/pest`
5. Fix code style: `./vendor/bin/pint`

### Pull Request Guidelines

- One feature per pull request
- Add tests for new functionality
- Document behavior changes in README.md
- Send coherent commit history (squash intermediate commits)
- Follow Laravel's versioning scheme with the convention `paradigm.major.minor` (where paradigm represents major architectural changes)
- Minor releases must not contain breaking changes

## Common Tasks

### Running the Application

```bash
# Make executable
chmod +x application

# Run command
./application [command]

# Or via symlink
sir [command]
```

### Building

```bash
php application app:build
```

### Testing Specific Suites

```bash
# Run feature tests
./vendor/bin/pest tests/Feature

# Run unit tests
./vendor/bin/pest tests/Unit

# Run specific test file
./vendor/bin/pest tests/Unit/ContextTest.php
```

## File Locations

- Commands: `app/Commands/`
- Services: `app/Services/`
- Tests: `tests/Feature/` and `tests/Unit/`
- Example workflows: `examples/workflows/`
- Example packs: `examples/packs/`
- Config: `config/`

## Important Notes

- This is a CLI tool - no web interface or HTTP layer
- Workflows are YAML files in `.sir/workflows/` directories
- Packs are Git repositories with `sir-pack.json` and `methods/` directory
- The application uses Laravel Zero's console kernel
- Global configuration lives in `~/.sir/`
- Project configuration can be in `./.sir/` at any level up to root

## Common Patterns

### Adding a New Built-in Task

1. Create task class in `app/Services/Tasks/`
2. Implement required interface/methods
3. Register in TaskRegistry
4. Add tests in `tests/Unit/Tasks/` or `tests/Feature/Tasks/`
5. Document in README.md

### Adding a New Command

1. Create command in `app/Commands/`
2. Extend Laravel Zero's Command class
3. Add tests in `tests/Feature/`
4. Update README.md if user-facing

### Working with Workflows

- Workflows are parsed from YAML
- Support inputs, steps, and variable context
- Steps can be tasks, methods, or shell commands
- Use `saveAs` to store step results in context
- Template expressions are resolved at execution time

## Security Considerations

- Always use `shellQuote()` when interpolating variables in shell commands
- Validate user inputs for workflows
- Be careful with file system operations (use absolute paths when possible)
- Pack installations from Git are sandboxed to pack directories
