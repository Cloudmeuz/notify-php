<?php

declare(strict_types=1);

namespace CloudMe\Notify\Auth;

/**
 * Persists the token pair to a single JSON file (created with 0600
 * permissions) so a CLI script or a low-traffic app run repeatedly doesn't
 * re-authenticate on every invocation. Not safe for concurrent writers from
 * multiple processes - use a shared cache (Redis, etc.) behind your own
 * {@see TokenStorage} implementation for that.
 */
final class FileTokenStorage implements TokenStorage
{
    public function __construct(private readonly string $path) {}

    public function get(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(array $tokens): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->path, json_encode($tokens, JSON_THROW_ON_ERROR));
        chmod($this->path, 0600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
