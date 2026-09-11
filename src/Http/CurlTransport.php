<?php

declare(strict_types=1);

namespace Synthigy\Http;

use Synthigy\SynthigyError;

/**
 * Default transport — ext-curl (bundled with virtually every PHP install),
 * so the SDK stays free of a Composer HTTP-client dependency. Keep-alive /
 * connection pooling across calls on the same Client comes from reusing
 * one curl handle for its lifetime.
 */
final class CurlTransport implements HttpTransport
{
    private ?\CurlHandle $handle = null;

    /**
     * One handle for this transport's lifetime, so keep-alive actually
     * keeps alive across calls. curl_init() returns false when the
     * extension can't allocate — surfaced as a normal SynthigyError here
     * rather than a TypeError from the first curl_* call downstream.
     */
    private function handle(): \CurlHandle
    {
        if ($this->handle === null) {
            $ch = curl_init();
            if ($ch === false) {
                throw new SynthigyError('failed to initialize curl', 'NETWORK_ERROR');
            }
            $this->handle = $ch;
        }
        return $this->handle;
    }

    public function request(string $method, string $url, ?string $body, array $headers, ?float $timeoutSeconds): HttpResponse
    {
        if ($url === '' || $method === '') {
            throw new SynthigyError('request needs a method and a URL', 'INVALID_BODY');
        }

        $ch = $this->handle();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_reset($ch);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TCP_KEEPALIVE => 1,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($timeoutSeconds !== null) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int)round($timeoutSeconds * 1000));
        }

        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseHeaders): int {
            $len = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        // CURLOPT_RETURNTRANSFER is set, so a successful exec yields the
        // body as a string; false signals a transport failure. (The bare
        // `true` return only happens without RETURNTRANSFER — treated as
        // an empty body rather than trusted blindly.)
        $result = curl_exec($ch);
        if ($result === false) {
            $errno = curl_errno($ch);
            $msg = curl_error($ch);
            $code = $errno === CURLE_OPERATION_TIMEDOUT ? 'TIMEOUT' : 'NETWORK_ERROR';
            throw new SynthigyError($msg !== '' ? $msg : 'network request failed', $code);
        }
        $responseBody = is_string($result) ? $result : '';

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, $responseHeaders, $responseBody);
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
        }
    }
}
