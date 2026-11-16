<?php

namespace App\Services\Relay;

use GuzzleHttp\Client;
use RuntimeException;

class RelayClient
{
    public function validateUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'], $parts['scheme'])
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || ! preg_match('/\A(?:[a-zA-Z0-9.-]+|\[::1\])\z/', $parts['host'])
            || ($parts['scheme'] !== 'https'
                && ! ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', '[::1]'], true)))) {
            throw new RuntimeException('Enter the HTTPS origin of the Sir relay. HTTP is available only on loopback.');
        }

        return $url;
    }

    public function post(string $url, string $endpoint, array $body, ?string $token = null): array
    {
        $url = $this->validateUrl($url);
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        try {
            $response = (new Client)->post($url.'/api/v1/device/'.$endpoint, [
                'json' => $body,
                'headers' => $headers,
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => 10,
                'read_timeout' => 10,
                'stream' => true,
                'proxy' => '',
            ]);
            if ($endpoint === 'sync' && $token !== null && $response->getStatusCode() === 403) {
                throw new RelayAccessUnavailable;
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException;
            }

            $stream = $response->getBody();
            $body = '';
            while (! $stream->eof() && strlen($body) < 8192) {
                $chunk = $stream->read(8192 - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (strlen($body) >= 8192 || ! is_array($data)) {
                throw new RuntimeException;
            }

            return $data;
        } catch (RelayAccessUnavailable $exception) {
            throw $exception;
        } catch (\Exception) {
            throw new RuntimeException('The Sir relay did not accept the request or returned an invalid response.');
        }
    }
}
