<?php

declare(strict_types=1);

namespace Synthigy\Tests\Fixtures;

use Synthigy\Http\HttpResponse;
use Synthigy\Http\HttpTransport;

/**
 * In-memory request/response recorder — no socket, no process. Queue
 * responses with respond(); every request is recorded in ->requests for
 * assertions. A handler closure can be queued instead of a canned
 * response when a test needs to react to the request (e.g. simulate a
 * 401-then-200 sequence, or echo the request body back).
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, body: ?string, headers: array<string,string>}> */
    public array $requests = [];

    /** @var list<HttpResponse|callable(string,string,?string,array<string,string>):HttpResponse> */
    private array $queue = [];

    public function respond(HttpResponse $response): self
    {
        $this->queue[] = $response;
        return $this;
    }

    /**
     * @param array<string,string> $headers
     */
    public function respondJson(int $status, mixed $body, array $headers = []): self
    {
        return $this->respond(new HttpResponse($status, $headers, (string)json_encode($body)));
    }

    /** @param callable(string,string,?string,array<string,string>):HttpResponse $handler */
    public function respondWith(callable $handler): self
    {
        $this->queue[] = $handler;
        return $this;
    }

    public function request(string $method, string $url, ?string $body, array $headers, ?float $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        if ($this->queue === []) {
            throw new \RuntimeException('FakeTransport: no queued response for ' . $method . ' ' . $url);
        }
        $next = array_shift($this->queue);
        return is_callable($next) ? $next($method, $url, $body, $headers) : $next;
    }

    /**
     * @return array<string,mixed>
     */
    public function lastRequestBody(): array
    {
        $raw = $this->requests[count($this->requests) - 1]['body'] ?? null;
        $decoded = $raw !== null ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
