#!/bin/sh
set -eu

# Usage: sh tests/smoke-local.sh /absolute/path/to/sir
sir=${1:?Pass the absolute path to the installed sir command}
profile=$(mktemp -d)
trap 'rm -rf "$profile"' EXIT

mkdir -p "$profile/home/.sir/workflows" "$profile/work"
export HOME="$profile/home"
cat > "$HOME/.sir/workflows/hello.yml" <<'YAML'
inputs:
  name: {required: true}
steps:
  - name: Write greeting
    shell: "printf 'Hello, %s!\\n' {{shellQuote(name)}} > greeting.txt"
YAML
cat > "$HOME/.sir/workflows/inputs.yml" <<'YAML'
inputs:
  first: {required: true}
  middle: {required: true}
  last: {required: true}
steps:
  - shell: "printf '%s %s %s\\n' {{shellQuote(first)}} {{shellQuote(middle)}} {{shellQuote(last)}} > inputs.txt"
YAML
cat > "$HOME/.sir/workflows/failure.yml" <<'YAML'
inputs:
  privateValue: {required: true}
steps:
  - name: Deliberate failure
    shell: ': {{shellQuote(privateValue)}}; exit 7'
  - name: Write the result of the preceding step
    shell: touch unexpected.txt
YAML
cat > "$HOME/.sir/workflows/timeout.yml" <<'YAML'
inputs:
  privateValue: {required: true}
steps:
  - name: Deliberate timeout
    timeout: 1
    shell: ': {{shellQuote(privateValue)}}; exec sleep 5'
YAML
cd "$profile/work"

"$sir" workflows:list > discovery.log
grep -F hello discovery.log

"$sir" run hello --dry-run --non-interactive --input='name=Sir Smoke' > preview.log
grep -F 'Would execute step 1' preview.log
test ! -e greeting.txt

if "$sir" run hello --non-interactive </dev/null > missing-input.log 2>&1; then
    echo 'Expected a missing required input to exit nonzero' >&2
    exit 1
fi
grep -F 'Missing required workflow input: name' missing-input.log
test ! -e greeting.txt

"$sir" run hello --non-interactive --input='name=Sir Smoke' </dev/null
test "$(cat greeting.txt)" = 'Hello, Sir Smoke!'

"$sir" run hello 'Sir Positional' --non-interactive </dev/null
test "$(cat greeting.txt)" = 'Hello, Sir Positional!'

"$sir" run inputs one three --input=middle=two --non-interactive </dev/null
test "$(cat inputs.txt)" = 'one two three'
"$sir" run inputs --input=last=c --input=first=a --input=middle=b --non-interactive </dev/null
test "$(cat inputs.txt)" = 'a b c'

if "$sir" run failure --non-interactive --input=privateValue=private-ci-value > failure.log 2>&1; then
    echo 'Expected the failure workflow to exit nonzero' >&2
    exit 1
fi
grep -F 'Deliberate failure' failure.log
grep -F 'exited with code 7' failure.log
grep -F 'Command: : [redacted]; exit 7' failure.log
grep -F 'rerun the original command' failure.log
if grep -F 'private-ci-value' failure.log; then
    echo 'Failure diagnostics exposed the input value' >&2
    exit 1
fi
test ! -e unexpected.txt


if "$sir" run timeout --non-interactive --input=privateValue=private-ci-value > timeout.log 2>&1; then
    echo 'Expected the timed out workflow to exit nonzero' >&2
    exit 1
fi
grep -F 'exceeded the timeout of 1 s' timeout.log
grep -F 'Command: : [redacted]; exec sleep 5' timeout.log
if grep -F 'private-ci-value' timeout.log; then
    echo 'Timeout diagnostics exposed the input value' >&2
    exit 1
fi

cat > "$HOME/.sir/workflows/preview.yml" <<'YAML'
inputs:
  argument: {required: true}
  secret: {default: private-default}
  public: {default: preview, publicDefault: true}
  protected: {default: hidden-value, publicDefault: true, sensitive: true}
steps:
  - name: Shell
    shell: 'touch preview-shell && printf %s {{shellQuote(argument)}}'
    saveAs: output
  - name: Task
    task: files.write
    with: {path: preview-task, content: hidden}
    if: '{{output}}'
  - name: Method
    method: unknown-method
    forEach: '{{output}}'
  - name: Static
    shell: 'touch preview-static'
    if: false
    forEach: [a, b]
YAML
printf 'touch preview-script\n' > "$HOME/.sir/workflows/opaque.sh"
printf 'steps: [\n' > "$HOME/.sir/workflows/broken.yml"
printf 'steps: [{shell: echo, task: x}]\n' > "$HOME/.sir/workflows/duplicate.yml"
printf 'steps: {first: {shell: echo}}\n' > "$HOME/.sir/workflows/mapping.yml"

"$sir" workflows:list --json > list.json
"$sir" workflows:show preview --json > show.json
"$sir" run preview --json --dry-run --input=argument=private-input > preview.json
"$sir" run opaque --json --dry-run > opaque.json
for scenario in missing-workflow broken duplicate mapping missing-input remote no-dry-run; do
    case "$scenario" in
        missing-workflow) set -- workflows:show missing --json ;;
        broken|duplicate) set -- workflows:show "$scenario" --json ;;
        mapping) set -- run mapping --dry-run --json ;;
        missing-input) set -- run preview --json --dry-run ;;
        remote) set -- run preview --json --dry-run --remote=ssh://unused ;;
        no-dry-run) set -- run preview --json --input=argument=private-input ;;
    esac
    if "$sir" "$@" > "$scenario.json"; then
        echo "Expected $scenario to exit nonzero" >&2
        exit 1
    fi
done

php <<'PHP'
<?php
function readJson(string $file): array {
    return json_decode(file_get_contents($file.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
function check(bool $condition, string $label): void {
    if (! $condition) {
        throw new RuntimeException($label);
    }
}
$list = readJson('list')['workflows'];
check(in_array('preview', array_column($list, 'name'), true), 'Discovery');
$show = readJson('show');
check(str_ends_with($show['workflow']['path'], '/preview.yml'), 'Source path');
check($show['inputs'][0]['required'], 'Required input');
check($show['inputs'][1]['defaultRedacted'] && $show['inputs'][3]['defaultRedacted'], 'Default redaction');
check($show['inputs'][2]['default'] === 'preview', 'Public default');
$preview = readJson('preview');
check(array_column($preview['steps'], 'type') === ['shell', 'task', 'method', 'shell'], 'Step order');
check($preview['steps'][1]['condition'] === 'unresolved', 'Dynamic condition');
check($preview['steps'][2]['loop'] === 'unresolved', 'Dynamic loop');
check($preview['steps'][3]['condition'] === 'false' && $preview['steps'][3]['loop'] === 2, 'Static condition and loop');
check(readJson('opaque')['opaque'], 'Opaque script');
foreach ([
    'missing-workflow' => 'workflow_not_found',
    'broken' => 'invalid_workflow',
    'duplicate' => 'invalid_workflow',
    'mapping' => 'invalid_workflow',
    'missing-input' => 'invalid_inputs',
    'remote' => 'remote_preview_unsupported',
    'no-dry-run' => 'dry_run_required',
] as $file => $error) {
    check(readJson($file)['error'] === $error, $file);
}
PHP
if grep -E 'private-default|private-input|hidden-value|touch preview-' show.json preview.json; then
    echo 'JSON exposed values or shell source' >&2
    exit 1
fi
for file in preview-shell preview-task preview-static preview-script; do test ! -e "$file"; done
