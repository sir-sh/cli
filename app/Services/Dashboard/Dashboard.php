<?php

namespace App\Services\Dashboard;

use App\Services\Approvals\PendingRuns;
use App\Services\Approvals\RunPlan;
use App\Services\ConfigLoader;
use App\Services\DashboardRegistry;
use App\Services\LayerResolver;
use App\Services\Workflows\WorkflowInputs;
use App\Services\Workflows\WorkflowInspection;

class Dashboard
{
    public function __construct(private WorkflowInspection $inspection, private RunPlan $plans, private PendingRuns $pending) {}

    public function render(string $method, string $path, array $query, array $form, string $csrf, string $prefix): string
    {
        $activity = $method === 'GET' && $path === '/run'
            ? $this->pending->activity(is_string($query['id'] ?? null) ? $query['id'] : '', getcwd() ?: '')
            : [];
        $updates = $method === 'GET' && ($path === '/runs' || in_array($activity['state'] ?? null, ['pending', 'running'], true));
        $body = match ([$method, $path]) {
            ['GET', '/'] => $this->workflows(),
            ['GET', '/preview'] => $this->preview($query['name'] ?? null, $csrf),
            ['POST', '/request'] => $this->request($form),
            ['GET', '/runs'] => $this->runs(),
            ['GET', '/run'] => $this->runActivity($activity),
            ['GET', '/configuration'] => $this->configuration(),
            ['GET', '/connections'] => $this->connections(),
            ['GET', '/registry'] => $this->registry($csrf),
            ['POST', '/registry/install'] => $this->install($form, $prefix),
            ['GET', '/draft'] => app(WorkflowDraft::class)->editor($csrf, $query['name'] ?? null),
            ['POST', '/draft/review'] => app(WorkflowDraft::class)->review($csrf, $form),
            ['POST', '/draft/save'] => app(WorkflowDraft::class)->save($form),
            ['GET', '/ask'] => app(AskSir::class)->render($csrf),
            ['POST', '/ask'] => app(AskSir::class)->render($csrf, $form['message'] ?? ''),
            default => '',
        };
        if ($body === '') {
            http_response_code(404);
            $body = '<h1>Page not found</h1>';
        }

        $project = self::escape(getcwd() ?: '');
        $base = self::escape($prefix);
        $style = <<<'CSS'
body{max-width:60rem;margin:2rem auto;padding:0 1rem;color:#251d13;background:#fcf2dc;font:1rem/1.5 system-ui,sans-serif}
header{border-bottom:4px double #9d8051}h1,h2{font-family:Georgia,serif}
nav{display:flex;flex-wrap:wrap;gap:1.1rem;padding:.75rem 0}a{color:#64491b}
li{margin:.4rem 0}code,pre{background:#efdfbc;padding:.15em .3em;overflow-wrap:anywhere}pre{white-space:pre-wrap}
input{display:block;box-sizing:border-box;width:min(100%,30rem);font:inherit;margin:.25rem 0 .7rem}
button{font:inherit;background:#efcf8c;border:1px solid #9d8051;padding:.4rem .7rem;border-radius:.25rem;cursor:pointer}
CSS;

        return '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .($updates ? '<meta http-equiv="refresh" content="5">' : '')
            .'<title>Sir Dashboard</title><style>'.$style.'</style><body><header><strong>Sir Dashboard</strong><p><code>'.$project.'</code></p>'
            .'<nav aria-label="Dashboard"><a href="'.$base.'/">Workflows</a><a href="'.$base.'/runs">Runs</a><a href="'.$base.'/registry">Registry</a>'
            .'<a href="'.$base.'/configuration">Configuration</a><a href="'.$base.'/connections">Connections</a>'
            .'<a href="'.$base.'/ask">Ask Sir</a></nav>'
            .'</header><main>'.$body.'</main></body></html>';
    }

    private function workflows(): string
    {
        $body = '<h1>Project workflows</h1>';
        $workflows = $this->inspection->listing()['workflows'];
        if ($workflows === []) {
            return $body.'<p>No workflows found. Browse the <a href="registry">registry</a> to install one.</p>';
        }

        $body .= '<ul>';
        foreach ($workflows as $workflow) {
            $name = self::escape($workflow['name']);
            if (in_array($workflow['type'], ['yml', 'yaml'], true)) {
                $name = '<a href="preview?name='.rawurlencode($workflow['name']).'">'.$name.'</a>';
            }
            $body .= '<li>'.$name.' <small>'.self::escape($workflow['type']).'</small></li>';
        }

        return $body.'</ul>';
    }

    private function preview(mixed $name, string $csrf): string
    {
        try {
            $plan = $this->resolve($name);
        } catch (\Throwable) {
            return $this->error('The workflow cannot be previewed.', 404);
        }

        $preview = $plan['preview'];
        $body = '<h1>'.self::escape($name).'</h1><p>Review the planned steps. Requesting a run leaves execution pending until you approve it in your terminal.</p>';
        $body .= '<p>Source <code>'.self::escape($preview['workflow']['path']).'</code></p><ol>';
        foreach ($preview['steps'] as $step) {
            $details = $step['type'];
            if ($step['condition'] !== null) {
                $details .= ', condition '.$step['condition'];
            }
            if ($step['loop'] !== null) {
                $details .= ', loop '.$step['loop'];
            }
            $body .= '<li>'.self::escape($step['name']).' <small>('.self::escape($details).')</small></li>';
        }
        $body .= '</ol><form action="request" method="post" autocomplete="off">'
            .'<input type="hidden" name="_csrf" value="'.self::escape($csrf).'">'
            .'<input type="hidden" name="name" value="'.self::escape($name).'">'
            .'<input type="hidden" name="fingerprint" value="'.$plan['fingerprint'].'">';
        foreach ($preview['inputs'] as $index => $input) {
            $private = $plan['workflow']['inputs'][$input['name']]['sensitive'] ?? false;
            $required = $input['required'] && ! $input['hasDefault'];
            $label = self::escape($input['name']).($input['hasDefault'] ? ' (default available)' : ($required ? ' (required)' : ''));
            $body .= '<label>'.$label.'<input name="inputs['.$index.']" type="'.($private ? 'password' : 'text').'"'
                .($required ? ' required' : '').'></label>';
        }

        return $body.'<button type="submit">Request run</button></form>';
    }

    private function request(array $form): string
    {
        $name = $form['name'] ?? null;
        try {
            $plan = $this->resolve($name);
        } catch (\Throwable) {
            return $this->error('The workflow cannot be requested.', 400);
        }

        if (! is_string($form['fingerprint'] ?? null) || ! hash_equals($plan['fingerprint'], $form['fingerprint'])) {
            return $this->error('The workflow changed. Open a fresh preview and review the steps again.', 409);
        }

        $values = $form['inputs'] ?? [];
        if (! is_array($values)) {
            return $this->error('Invalid workflow inputs.', 400);
        }

        $assignments = [];
        foreach ($values as $index => $value) {
            $input = $plan['preview']['inputs'][$index] ?? null;
            if ($input === null || ! is_string($value)) {
                return $this->error('Invalid workflow inputs.', 400);
            }
            if ($value !== '') {
                $assignments[] = $input['name'].'='.$value;
            }
        }

        try {
            $inputs = WorkflowInputs::resolve($plan['workflow'], [], $assignments);
        } catch (\InvalidArgumentException) {
            return $this->error('Fill in the required workflow inputs.', 400);
        }

        try {
            $id = $this->pending->create([
                'project' => $plan['preview']['project'],
                'name' => $name,
                'fingerprint' => $plan['fingerprint'],
                'inputs' => $inputs,
            ]);
        } catch (\Throwable) {
            return $this->error('The private approval store is unavailable.', 503);
        }

        http_response_code(201);

        return '<h1>Run requested</h1><p>In your terminal, review and approve the request with <code>sir approve '.$id.'</code>.</p>'
            .'<p>The request expires in ten minutes. <a href="runs">View run history</a></p>';
    }

    private function runs(): string
    {
        $runs = $this->pending->history(getcwd() ?: '');
        $body = '<h1>Run history</h1><p>Updates every five seconds.</p>';
        if ($runs === []) {
            return $body.'<p>No recent requests for this project.</p>';
        }

        $body .= '<ol>';
        foreach ($runs as $run) {
            $body .= '<li><strong>'.self::escape($run['state']).'</strong> <a href="run?id='.$run['requestId'].'"><code>'.$run['requestId'].'</code></a><ul>';
            foreach (['requestedAt' => 'Requested', 'startedAt' => 'Started', 'finishedAt' => 'Finished'] as $key => $event) {
                if ($run[$key] !== null) {
                    $body .= '<li>'.$event.' '.gmdate('Y-m-d H:i:s', $run[$key]).' UTC</li>';
                }
            }
            if ($run['outcome'] !== null) {
                $body .= '<li>Outcome: '.self::escape($run['outcome']).'</li>';
            }
            if ($run['state'] === 'pending') {
                $body .= '<li>Review in your terminal with <code>sir approve '.$run['requestId'].'</code></li>';
            }
            $body .= '</ul></li>';
        }

        return $body.'</ol>';
    }

    private function runActivity(array $run): string
    {
        if (isset($run['error'])) {
            return $this->error('This run is unavailable.', 404);
        }

        $body = '<h1>Run activity</h1><p><code>'.self::escape($run['requestId']).'</code> &middot; '.self::escape($run['state']).'</p>'
            .'<p>Activity includes timestamps and step outcomes. Workflow output, commands, and inputs are excluded.</p>'
            .'<p><a href="runs">Back to run history</a></p><ol>';
        $body .= '<li>'.gmdate('Y-m-d H:i:s', $run['requestedAt']).' UTC &middot; Run requested</li>';
        if ($run['startedAt'] !== null) {
            $body .= '<li>'.gmdate('Y-m-d H:i:s', $run['startedAt']).' UTC &middot; Run started</li>';
        }
        foreach ($run['stepEvents'] as $event) {
            $body .= '<li>'.gmdate('Y-m-d H:i:s', $event['at']).' UTC &middot; Step '.$event['step'].' '.$event['event'].'</li>';
        }
        if ($run['stepEventsTruncated']) {
            $body .= '<li>Additional step activity omitted.</li>';
        }
        if ($run['outcome'] !== null) {
            $time = $run['finishedAt'] === null ? '' : gmdate('Y-m-d H:i:s', $run['finishedAt']).' UTC &middot; ';
            $body .= '<li>'.$time.'Outcome: '.self::escape(str_replace('_', ' ', $run['outcome'])).'</li>';
        }
        if ($run['state'] === 'pending') {
            $body .= '<li>Review in your terminal with <code>sir approve '.self::escape($run['requestId']).'</code></li>';
        }

        return $body.'</ol>';
    }

    private function configuration(): string
    {
        $layers = app(LayerResolver::class)->resolve();
        $body = '<h1>Configuration</h1><p>Sir configuration layers, nearest first. Settings and secret values stay in their files.</p>';
        if ($layers === []) {
            return $body.'<p>No .sir directories found.</p>';
        }

        $body .= '<ol>';
        foreach ($layers as $layer) {
            $files = array_filter(['config.yml', 'config.json'], fn (string $file): bool => is_file($layer.'/'.$file));
            $body .= '<li><code>'.self::escape($layer).'</code> &middot; '.($files === [] ? 'No configuration files' : implode(', ', $files)).'</li>';
        }

        return $body.'</ol>';
    }

    private function connections(): string
    {
        $body = '<h1>Connections</h1><p>Remote target names and types from the active Sir configuration layers. Endpoints, credentials, and environment values stay private. Targets are listed without being contacted.</p>';
        try {
            $targets = app(ConfigLoader::class)->loadConfig()['remotes'] ?? [];
        } catch (\Exception) {
            return $body.'<p>Could not read the Sir configuration.</p>';
        }
        if (! is_array($targets) || $targets === []) {
            return $body.'<p>No remote targets defined in the active configuration layers.</p>';
        }

        $body .= '<ul>';
        foreach ($targets as $name => $target) {
            $type = is_array($target) ? ($target['type'] ?? null) : null;
            $type = in_array($type, ['ssh', 'docker', 'k8s', 'aws-lambda'], true) ? $type : 'unknown';
            $body .= '<li><code>'.self::escape((string) $name).'</code> &middot; '.$type.'</li>';
        }

        return $body.'</ul>';
    }

    private function registry(string $csrf): string
    {
        try {
            return app(DashboardRegistry::class)->render($csrf);
        } catch (\RuntimeException $error) {
            return $this->error($error->getMessage(), 502);
        }
    }

    private function install(array $form, string $prefix): string
    {
        try {
            app(DashboardRegistry::class)->install($form);
        } catch (\Exception $error) {
            return $this->error($error->getMessage(), 400);
        }

        header('Location: '.$prefix.'/registry', true, 303);

        return '<p>Workflow installed.</p>';
    }

    private function resolve(mixed $name): array
    {
        if (! is_string($name) || $name === '') {
            throw new \InvalidArgumentException('A workflow name is required.');
        }

        return $this->plans->resolve($name);
    }

    private function error(string $message, int $status): string
    {
        http_response_code($status);

        return '<h1>Request unavailable</h1><p>'.self::escape($message).'</p>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
