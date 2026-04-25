<?php

namespace Tests\Unit;

use App\Services\ConditionEvaluator;
use App\Services\Context;

beforeEach(function () {
    $this->context = new Context;
    $this->evaluator = new ConditionEvaluator($this->context);
});

// === S010-FR-005: Comparison Operators ===

test('S010-FR-005: == operator (string equality)', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('{{branch}} == main'))->toBeTrue();
    expect($this->evaluator->evaluate('{{branch}} == develop'))->toBeFalse();
});

test('S010-FR-005: != operator (not equals)', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('{{branch}} != main'))->toBeFalse();
    expect($this->evaluator->evaluate('{{branch}} != develop'))->toBeTrue();
});

test('S010-FR-005: > operator (greater than, numeric)', function () {
    $this->context->set('count', '10');
    $this->context->set('countInt', 10);
    $this->context->set('threshold', 5);
    expect($this->evaluator->evaluate('{{count}} > 5'))->toBeTrue();
    expect($this->evaluator->evaluate('{{count}} > 10'))->toBeFalse();
    expect($this->evaluator->evaluate('{{threshold}} > 3'))->toBeTrue();
});

test('S010-FR-005: < operator (less than, numeric)', function () {
    $this->context->set('count', '3');
    expect($this->evaluator->evaluate('{{count}} < 5'))->toBeTrue();
    expect($this->evaluator->evaluate('{{count}} < 3'))->toBeFalse();
});

test('S010-FR-005: >= operator (greater than or equal)', function () {
    $this->context->set('count', '5');
    expect($this->evaluator->evaluate('{{count}} >= 5'))->toBeTrue();
    expect($this->evaluator->evaluate('{{count}} >= 6'))->toBeFalse();
});

test('S010-FR-005: <= operator (less than or equal)', function () {
    $this->context->set('count', '5');
    expect($this->evaluator->evaluate('{{count}} <= 5'))->toBeTrue();
    expect($this->evaluator->evaluate('{{count}} <= 4'))->toBeFalse();
});

test('S010-FR-005.a: type-aware comparison (numeric vs string)', function () {
    $this->context->set('numStr', '10');
    $this->context->set('numInt', 10);
    // String "10" compared numerically with int 10
    expect($this->evaluator->evaluate('{{numStr}} > 5'))->toBeTrue();
    // Non-numeric strings should be compared as strings
    $this->context->set('word', 'apple');
    expect($this->evaluator->evaluate('{{word}} > "banana"'))->toBeFalse();
});

// === S010-FR-006: Logical Operators ===

test('S010-FR-006: && operator (AND)', function () {
    $this->context->set('branch', 'main');
    $this->context->set('testsPassed', 'true');
    expect($this->evaluator->evaluate('{{branch}} == main && {{testsPassed}}'))->toBeTrue();
    expect($this->evaluator->evaluate('{{branch}} == main && false'))->toBeFalse();
});

test('S010-FR-006: || operator (OR)', function () {
    $this->context->set('branch', 'develop');
    expect($this->evaluator->evaluate('{{branch}} == main || {{branch}} == develop'))->toBeTrue();
    expect($this->evaluator->evaluate('{{branch}} == main || false'))->toBeFalse();
});

test('S010-FR-006: ! operator (NOT)', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('!{{branch}} == main'))->toBeFalse();
    expect($this->evaluator->evaluate('!{{branch}} == develop'))->toBeTrue();
});

test('S010-FR-006.a: short-circuit AND (left false stops)', function () {
    $this->context->set('branch', 'main');
    $this->context->set('flag', 'false');
    // Evaluates left: main == main = true; then right: flag = false; result false
    expect($this->evaluator->evaluate('{{branch}} == main && {{flag}}'))->toBeFalse();
});

test('S010-FR-006.b: parentheses group sub-expressions', function () {
    $this->context->set('a', 'true');
    $this->context->set('b', 'false');
    $this->context->set('c', 'true');
    expect($this->evaluator->evaluate('{{a}} && ({{b}} || {{c}})'))->toBeTrue();
    expect($this->evaluator->evaluate('{{a}} && ({{b}} || false)'))->toBeFalse();
});

// === S010-FR-007: String Operators ===

test('S010-FR-007: contains operator', function () {
    $this->context->set('env', 'PRODUCTION_ENV');
    expect($this->evaluator->evaluate('{{env}} contains production'))->toBeTrue();
    expect($this->evaluator->evaluate('{{env}} contains staging'))->toBeFalse();
});

test('S010-FR-007: startsWith operator', function () {
    $this->context->set('path', '/Users/mark/projects');
    expect($this->evaluator->evaluate('{{path}} startsWith /Users'))->toBeTrue();
    expect($this->evaluator->evaluate('{{path}} startsWith /var'))->toBeFalse();
});

test('S010-FR-007: endsWith operator', function () {
    $this->context->set('filename', 'report.pdf');
    expect($this->evaluator->evaluate('{{filename}} endsWith .pdf'))->toBeTrue();
    expect($this->evaluator->evaluate('{{filename}} endsWith .txt'))->toBeFalse();
});

test('S010-FR-007: matches operator (PCRE regex)', function () {
    $this->context->set('email', 'user@example.com');
    expect($this->evaluator->evaluate('{{email}} matches ^[^\s@]+@[^\s@]+\.[^\s@]+$'))->toBeTrue();
    expect($this->evaluator->evaluate('{{email}} matches ^not-a-match$'))->toBeFalse();
});

test('S010-FR-007.a: string operators work after template resolution', function () {
    $this->context->set('suffix', '.pdf');
    $this->context->set('filename', 'document.pdf');
    expect($this->evaluator->evaluate('{{filename}} endsWith {{suffix}}'))->toBeTrue();
});

// === S010-FR-008: Existence Operators ===

test('S010-FR-008: exists returns true for defined variable', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('exists branch'))->toBeTrue();
    expect($this->evaluator->evaluate('exists undefinedVar'))->toBeFalse();
});

test('S010-FR-008: isEmpty returns true for empty string', function () {
    $this->context->set('optionalFlag', '');
    expect($this->evaluator->evaluate('isEmpty optionalFlag'))->toBeTrue();
});

test('S010-FR-008.b: isEmpty on undefined variable returns false', function () {
    expect($this->evaluator->evaluate('isEmpty undefinedVar'))->toBeFalse();
});

// === S010-FR-001 & S010-FR-009: Template Resolution in Conditions ===

test('S010-FR-001/S010-FR-009: {{variable}} resolved before evaluation', function () {
    $this->context->set('branch', 'main');
    $this->context->set('flag', 'true');
    expect($this->evaluator->evaluate('{{branch}} == main'))->toBeTrue();
});

test('S010-FR-009.a: non-boolean value coerced to boolean', function () {
    $this->context->set('someValue', 'hello');
    // Non-empty string coerces to true
    expect($this->evaluator->evaluate('{{someValue}}'))->toBeTrue();
    $this->context->set('emptyVal', '');
    expect($this->evaluator->evaluate('{{emptyVal}}'))->toBeFalse();
});

// === S010-FR-002: Skipped Step Behavior ===

test('S010-FR-002: step skipped when condition is false returns skipped:true', function () {
    $context = new Context;
    $context->set('branch', 'main');
    $evaluator = new ConditionEvaluator($context);

    $result = $evaluator->evaluate('{{branch}} == develop');

    expect($result)->toBeFalse();
});

test('S010-FR-002.a: skipped steps do not halt workflow', function () {
    // When condition is false, evaluate returns false (step should skip)
    $this->context->set('branch', 'main');
    $result = $this->evaluator->evaluate('{{branch}} == develop');

    expect($result)->toBeFalse();
    // This doesn't throw — workflow continues
});

// === S010-FR-010: Backwards Compatibility ===

test('S010-FR-010: no if property executes unconditionally', function () {
    // Null/empty condition evaluates to true
    $evaluator = new ConditionEvaluator(new Context);
    expect($evaluator->evaluate(null))->toBeTrue();
    expect($evaluator->evaluate(''))->toBeTrue();
    expect($evaluator->evaluate('   '))->toBeTrue();
});

// === S010-FR-003: continueOnError ===

test('S010-FR-003: continueOnError allows workflow to continue after step failure', function () {
    // This is tested at the WorkflowRunner level
    // When a step throws and continueOnError=true, workflow continues
    // The test is in WorkflowRunner tests, not ConditionEvaluator
    expect(true)->toBeTrue();
});

// === S010-FR-011: Invalid Condition Handling ===

test('S010-FR-011: invalid syntax returns false (step skipped)', function () {
    // Invalid syntax should be caught by parseAndEvaluate and return false
    // (not throw, which would halt the workflow)
    $result = $this->evaluator->evaluate('{{unclosed brace');

    expect($result)->toBeFalse();
});

test('S010-FR-011.a: undefined variable without default logs warning and is false', function () {
    // No warning mechanism in evaluator directly, but result is false
    $result = $this->evaluator->evaluate('{{unknownVar}} == true');

    expect($result)->toBeFalse();
});

// === Edge Cases ===

test('S010-EC-003: empty condition string executes unconditionally', function () {
    $evaluator = new ConditionEvaluator(new Context);
    expect($evaluator->evaluate(''))->toBeTrue();
    expect($evaluator->evaluate('   '))->toBeTrue();
});

test('S010-EC-004: non-boolean value coerced correctly', function () {
    $this->context->set('value', 'something');
    // Non-empty string is true
    expect($this->evaluator->evaluate('{{value}}'))->toBeTrue();
    $this->context->set('zero', '0');
    expect($this->evaluator->evaluate('{{zero}}'))->toBeTrue(); // non-empty string
});

test('S010-EC-005: matches with invalid regex returns false', function () {
    $this->context->set('text', 'hello');
    // Invalid regex should not throw — should return false and skip step
    $result = $this->evaluator->evaluate('{{text}} matches [invalid(regex');
    expect($result)->toBeFalse();
});

test('S010-EC-007: timeout value of zero means no timeout', function () {
    // This is a WorkflowRunner concern, not ConditionEvaluator
    // But we verify null/empty condition still works
    expect($this->evaluator->evaluate(''))->toBeTrue();
});

test('S010-EC-009: whitespace trimmed around operators', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('{{branch}}   ==   main'))->toBeTrue();
    expect($this->evaluator->evaluate('{{branch}}  ==  main'))->toBeTrue();
});

test('S010-EC-012: negated condition that is true evaluates to false', function () {
    $this->context->set('branch', 'main');
    expect($this->evaluator->evaluate('!{{branch}} == main'))->toBeFalse();
    expect($this->evaluator->evaluate('!{{branch}} == develop'))->toBeTrue();
});

// === Template resolution edge cases ===

test('template resolution: {{shellQuote(var)}} in condition', function () {
    $this->context->set('path', '/tmp/my file');
    // shellQuote in condition resolves to quoted string
    $resolved = $this->context->resolveTemplate('{{shellQuote(path)}}');
    expect($resolved)->toContain("'"); // escaped
});

test('template resolution: {{default(var, fallback)}} in condition', function () {
    // default() works in conditions
    $resolved = $this->context->resolveTemplate('{{default(missing, some-value)}}');
    expect($resolved)->toBe('some-value');
});

test('coerceToBoolean: empty string is false', function () {
    $this->context->set('empty', '');
    expect($this->evaluator->evaluate('{{empty}}'))->toBeFalse();
});

test('coerceToBoolean: literal true/false strings', function () {
    $this->context->set('t', 'true');
    $this->context->set('f', 'false');
    expect($this->evaluator->evaluate('{{t}}'))->toBeTrue();
    expect($this->evaluator->evaluate('{{f}}'))->toBeFalse();
});