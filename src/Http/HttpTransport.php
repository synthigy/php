<?php

declare(strict_types=1);

namespace Synthigy\Http;

use Synthigy\SynthigyError;

/**
 * The one seam between the SDK and the network. CurlTransport is the real
 * implementation; tests inject a fake so the unit suite never opens a
 * socket. Transport-level failures (DNS, TCP, timeout) throw
 * SynthigyError(code: NETWORK_ERROR or TIMEOUT) — HTTP-level errors
 * (4xx/5xx) are returned as a normal HttpResponse for the caller to map.
 */
interface HttpTransport
{
    /**
     * @param array<string,string> $headers
     * @throws SynthigyError on transport failure
     */
    public function request(string $method, string $url, ?string $body, array $headers, ?float $timeoutSeconds): HttpResponse;
}
