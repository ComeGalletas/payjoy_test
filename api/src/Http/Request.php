<?php

declare(strict_types=1);

namespace FxInstallments\Http;

/**
 * An incoming HTTP request: method, path (no query string), query parameters, headers
 * (lowercase names), raw body and the peer address (REMOTE_ADDR).
 */
final class Request
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers any case; stored lowercase
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        array $headers = [],
        private readonly string $body = '',
        private readonly string $remoteAddr = '127.0.0.1',
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalized;
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $queryStart = strpos($uri, '?');
        $path = $queryStart === false ? $uri : substr($uri, 0, $queryStart);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $key = (string) $key;
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', strtolower($key))] = $value;
            }
        }
        // Some SAPIs only expose the Authorization header after a rewrite.
        if (!isset($headers['authorization']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $body = file_get_contents('php://input');

        return new self(
            strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET'),
            $path,
            $_GET,
            $headers,
            $body === false ? '' : $body,
            is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * The TCP peer address. Use ClientIp to get the client address behind a trusted proxy.
     */
    public function remoteAddr(): string
    {
        return $this->remoteAddr;
    }
}
