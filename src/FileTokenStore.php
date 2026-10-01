<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * TokenStore on the local filesystem: one 0600 file per token in a 0700 directory owned by the PHP process.
 *
 * A cache, never a failure: when the directory can't be used safely the store reads nothing and writes
 * nothing, and the SDK mints tokens per request as it would without a store.
 *
 * @phpstan-import-type StoredToken from TokenStore
 */
final class FileTokenStore implements TokenStore
{
    private readonly string $directory;
    private ?bool $usable = null;

    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? self::defaultDirectory(), '/\\');
    }

    /** Per-user directory under the system temp dir, so processes running as different users never share one. */
    public static function defaultDirectory(): string
    {
        $user = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'php';
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthigy-tokens-' . $user;
    }

    public function get(string $key): ?array
    {
        if (!$this->usable()) {
            return null;
        }
        $raw = @file_get_contents($this->path($key));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['token'] ?? null) || !is_numeric($data['expires_at'] ?? null)) {
            return null;
        }
        return ['token' => $data['token'], 'expires_at' => (float)$data['expires_at']];
    }

    public function put(string $key, string $token, float $expiresAt): void
    {
        if (!$this->usable()) {
            return;
        }
        $tmp = @tempnam($this->directory, 'tok');
        if ($tmp === false) {
            return;
        }
        $json = json_encode(['token' => $token, 'expires_at' => $expiresAt]);
        // rename() is atomic, so a concurrent reader sees the old file or the new one, never half of one
        if ($json === false
            || @file_put_contents($tmp, $json) === false
            || !@chmod($tmp, 0600)
            || !@rename($tmp, $this->path($key))) {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        if ($this->usable()) {
            @unlink($this->path($key));
        }
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.json';
    }

    private function usable(): bool
    {
        if ($this->usable !== null) {
            return $this->usable;
        }
        if (!is_dir($this->directory) && !is_link($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }
        $usable = is_dir($this->directory) && !is_link($this->directory) && is_writable($this->directory);
        if ($usable && function_exists('posix_geteuid')) {
            // never read tokens from, or write them into, a directory another user controls
            $usable = @fileowner($this->directory) === posix_geteuid();
            if ($usable && ((int)@fileperms($this->directory) & 0077) !== 0) {
                $usable = @chmod($this->directory, 0700);
            }
        }
        return $this->usable = $usable;
    }
}
