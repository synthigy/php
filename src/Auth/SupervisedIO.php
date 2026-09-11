<?php

declare(strict_types=1);

namespace Synthigy\Auth;

use Synthigy\SynthigyError;

/**
 * Process-wide singleton for the `SYNTHIGY_SUPERVISED=1` `auth.token`
 * JSON-RPC-over-stdio protocol (docs/plans/PLAN-EXEC-IDENTITY.md steps
 * 3-4). CLI-only: a script run under `synthigy exec`/`agent` (or a
 * robotics commander) owns the process's stdio and answers `auth.token`
 * asks over it, so the SDK never mints a token locally under this mode.
 * Meaningless under PHP-FPM (no stdio to a supervising parent) — do not
 * enable SYNTHIGY_SUPERVISED there.
 *
 * PHP has no background-thread analog of the Go/Python reference reader
 * (ZTS builds aside, out of scope here), so each ask blocks reading STDIN
 * line-by-line up to a 5s timeout, discarding any line that isn't a
 * well-formed `jsonrpc: "2.0"` frame for this request's id — a script that
 * also wants to read its own stdin for other purposes cannot share it with
 * this mode. One process-wide token cache (not per-Client), same 30s
 * pre-expiry buffer as TokenManager.
 */
final class SupervisedIO
{
    private const ASK_TIMEOUT_SECONDS = 5.0;

    private static ?self $instance = null;

    /** @var array<string, array{token: string, expiresAt: float}> */
    private array $tokens = [];

    private int $nextId = 0;

    /** @var resource|null */
    private $stdin = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function getToken(?string $audience): string
    {
        $key = $audience ?? '';
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && microtime(true) < $cached['expiresAt'] - 30) {
            return $cached['token'];
        }

        $params = [];
        if ($audience) {
            $params['audience'] = $audience;
        }
        $frame = $this->ask('auth.token', $params);
        if ($frame === null) {
            throw new SynthigyError(
                'Timed out waiting for auth.token from the supervising parent — a '
                . 'hung or missing parent must not hang the bot. Check the parent '
                . 'process (synthigy exec/agent, or the robotics commander) is '
                . 'still connected.',
                'NO_TOKEN',
            );
        }
        if (isset($frame['error'])) {
            $msg = is_array($frame['error']) ? ($frame['error']['message'] ?? null) : null;
            throw new SynthigyError($msg ?: 'auth.token request denied', 'NO_TOKEN');
        }

        $result = $frame['result'] ?? null;
        $token = is_array($result) ? ($result['token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new SynthigyError('auth.token response carried no token', 'NO_TOKEN');
        }

        $expiresIn = is_numeric($result['expires_in'] ?? null) ? (float)$result['expires_in'] : 300.0;
        $this->tokens[$key] = ['token' => $token, 'expiresAt' => microtime(true) + $expiresIn];
        return $token;
    }

    public function clear(): void
    {
        $this->tokens = [];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null null on timeout, EOF, or a write failure
     */
    private function ask(string $method, array $params): ?array
    {
        $id = ++$this->nextId;
        $frame = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        if ($frame === false || @fwrite(STDOUT, $frame . "\n") === false) {
            return null;
        }
        fflush(STDOUT);

        $this->stdin ??= STDIN;
        $deadline = microtime(true) + self::ASK_TIMEOUT_SECONDS;
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            $read = [$this->stdin];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, (int)$remaining, (int)(($remaining - (int)$remaining) * 1_000_000));
            if ($ready === false || $ready === 0) {
                continue;
            }
            $line = fgets($this->stdin);
            if ($line === false) {
                return null; // EOF — parent gone
            }
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded) || ($decoded['jsonrpc'] ?? null) !== '2.0') {
                continue;
            }
            if (($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
            // A frame for a different in-flight ask — this simple
            // synchronous reader only tracks one ask at a time, so a
            // mismatched id is dropped rather than queued.
        }
        return null;
    }
}
