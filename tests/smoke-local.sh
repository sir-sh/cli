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
