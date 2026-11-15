<?php

namespace App\Services;

use App\Services\Workflows\WorkflowSummary;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class DashboardRegistry
{
    private const CATALOG = 'https://www.sir.sh/api/workflows';

    private const SOURCE = 'https://github.com/sir-sh/workflows';

    private const RAW = 'https://raw.githubusercontent.com/sir-sh/workflows/';

    private const CATALOG_LIMIT = 2_097_152;

    private const WORKFLOW_LIMIT = 131_072;

    public function render(string $csrf): string
    {
        $catalog = $this->catalog();
        $revision = $catalog['revision'];
        $html = '<h1>Workflow registry</h1><p>Browse workflows from <a href="'.self::SOURCE.'">sir-sh/workflows</a>. Review the YAML before approving a project install. Installation does not run the workflow.</p>';
        $html .= '<p>Registry revision <code>'.$revision.'</code></p>';

        foreach ($catalog['workflows'] as $item) {
            $path = $item['path'];
            $filename = $this->escape(basename($path));
            $html .= '<article><h2>'.$this->escape($item['title']).'</h2><p>'.$this->escape($item['description']).'</p>';
            $html .= '<p><a href="'.self::SOURCE.'/blob/'.$revision.'/'.$path.'">View pinned source</a></p>';
            $html .= '<details><summary>Preview YAML</summary><pre>'.$this->escape($item['yaml']).'</pre></details>';
            $html .= '<form method="post" action="registry/install">'
                .'<input type="hidden" name="_csrf" value="'.$this->escape($csrf).'">'
                .'<input type="hidden" name="revision" value="'.$revision.'">'
                .'<input type="hidden" name="path" value="'.$path.'">'
                .'<input type="hidden" name="digest" value="'.hash('sha256', $item['yaml']).'">'
                .'<p>Install into <code>.sir/workflows/'.$filename.'</code> in the dashboard project.</p>'
                .'<button type="submit">Approve install of '.$filename.'</button></form></article>';
        }

        if ($catalog['workflows'] === []) {
            $html .= '<p>The registry has no published workflows.</p>';
        }

        return $html;
    }

    public function install(array $input): void
    {
        $revision = $input['revision'] ?? null;
        $path = $input['path'] ?? null;
        $digest = $input['digest'] ?? null;

        if (! $this->matches('/\A[0-9a-f]{40}\z/', $revision) || ! $this->validPath($path)
            || ! $this->matches('/\A[0-9a-f]{64}\z/', $digest)) {
            throw new RuntimeException('Invalid workflow approval.');
        }

        $catalog = $this->catalog();
        if ($catalog['revision'] !== $revision || ! isset($catalog['workflows'][$path])
            || ! hash_equals(hash('sha256', $catalog['workflows'][$path]['yaml']), $digest)) {
            throw new RuntimeException('The registry entry changed. Reload the registry and review the new revision.');
        }

        $yaml = $this->fetch(self::RAW.$revision.'/'.$path, self::WORKFLOW_LIMIT);
        if (! hash_equals($digest, hash('sha256', $yaml))) {
            throw new RuntimeException('The pinned download did not match the reviewed YAML.');
        }

        try {
            $definition = Yaml::parse($yaml);
        } catch (ParseException) {
            throw new RuntimeException('The registry workflow contains invalid YAML.');
        }

        if (! is_array($definition) || empty($definition['steps'])) {
            throw new RuntimeException('The downloaded file is not a workflow definition.');
        }

        $filename = basename($path);
        $definition['_meta'] = ['type' => pathinfo($filename, PATHINFO_EXTENSION)];
        try {
            WorkflowSummary::describe($definition);
        } catch (InvalidArgumentException) {
            throw new RuntimeException('The downloaded file is not a workflow definition.');
        }

        $this->save($filename, $yaml);
    }

    private function catalog(): array
    {
        $catalog = json_decode($this->fetch(self::CATALOG, self::CATALOG_LIMIT), true, 32);
        if (! is_array($catalog) || ($catalog['source'] ?? null) !== self::SOURCE
            || ! $this->matches('/\A[0-9a-f]{40}\z/', $catalog['revision'] ?? null)
            || ! is_array($catalog['workflows'] ?? null) || ! array_is_list($catalog['workflows'])) {
            throw new RuntimeException('The workflow registry returned an invalid catalog.');
        }

        $items = [];
        foreach ($catalog['workflows'] as $item) {
            if (! is_array($item) || ! $this->validPath($item['path'] ?? null)
                || ! is_string($item['title'] ?? null) || ! is_string($item['description'] ?? null)
                || ! is_string($item['yaml'] ?? null) || strlen($item['yaml']) > self::WORKFLOW_LIMIT
                || isset($items[$item['path']])) {
                throw new RuntimeException('The workflow registry returned an invalid entry.');
            }

            $items[$item['path']] = $item;
        }

        return ['revision' => $catalog['revision'], 'workflows' => $items];
    }

    private function validPath(mixed $path): bool
    {
        return is_string($path) && strlen($path) <= 250
            && $this->matches('~\Aworkflows/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+\.ya?ml\z~', $path);
    }

    private function matches(string $pattern, mixed $value): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    protected function fetch(string $url, int $limit): string
    {
        $context = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 0]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Could not contact the workflow registry.');
        }

        try {
            $status = stream_get_meta_data($stream)['wrapper_data'][0] ?? '';
            $body = stream_get_contents($stream, $limit + 1);
            if (! preg_match('~^HTTP/\S+ 200(?: |$)~', $status)
                || $body === false || strlen($body) > $limit || ! feof($stream)) {
                throw new RuntimeException('The workflow registry returned an invalid response.');
            }

            return $body;
        } finally {
            fclose($stream);
        }
    }

    private function save(string $filename, string $yaml): void
    {
        $project = getcwd();
        if ($project === false) {
            throw new RuntimeException('The dashboard project directory is unavailable.');
        }

        $directory = $project;
        foreach (['.sir', 'workflows'] as $part) {
            $directory .= DIRECTORY_SEPARATOR.$part;
            if (is_link($directory) || (! is_dir($directory) && ! @mkdir($directory, 0755) && ! is_dir($directory))
                || is_link($directory)) {
                throw new RuntimeException('Could not create a regular project workflows directory.');
            }
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        foreach (['yaml', 'yml', 'sh', 'php'] as $extension) {
            $existing = $directory.DIRECTORY_SEPARATOR.$name.'.'.$extension;
            if (file_exists($existing) || is_link($existing)) {
                throw new RuntimeException('A workflow with that name already exists in this project.');
            }
        }

        $target = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporary = @tempnam($directory, '.sir-install-');
        if ($temporary === false) {
            throw new RuntimeException('Could not stage the workflow installation.');
        }

        try {
            if (dirname($temporary) !== $directory || file_put_contents($temporary, $yaml) !== strlen($yaml)
                || ! @link($temporary, $target)) {
                throw new RuntimeException('Could not install the workflow without overwriting an existing file.');
            }
        } finally {
            unlink($temporary);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
