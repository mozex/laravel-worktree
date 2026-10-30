<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use JsonException;
use Mozex\Worktree\Exceptions\WorktreeException;

/**
 * The worktrees this package provisioned for one clone, kept as JSON in the
 * repository's git directory, outside the working tree and so never committed.
 *
 * Each entry records what setup actually made: the name, the driver that
 * served it, and every database with the server it was created on. Teardown
 * reads it back instead of deriving those from the configuration again, which
 * would miss the databases of a worktree set up before a name template
 * changed, and unserve through a driver that never served it.
 *
 * @phpstan-type Database array{connection: string, database: string, dsn: string, test: bool}
 * @phpstan-type DatabaseFile array{path: string, source: string}
 * @phpstan-type Entry array{path: string, name: string, branch: string|null, tool: string, driver: string, secure: bool, host: string, url: string|null, databases: list<Database>, files: list<DatabaseFile>, created: string}
 */
class Registry
{
    /**
     * The format this release writes. A property, not a constant: type
     * coverage asks a constant for a type, and typed constants need PHP 8.3.
     */
    protected static int $version = 1;

    /**
     * @param  list<Entry>  $entries
     */
    public function __construct(protected array $entries = []) {}

    /**
     * A missing file is an empty registry. One that cannot be read is never
     * mistaken for an empty one, which saving would then write over.
     */
    public static function fromFile(string $file): self
    {
        if (! is_file($file)) {
            return new self;
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw WorktreeException::unreadableRegistry($file);
        }

        return static::fromJson($contents, $file);
    }

    public static function fromJson(string $json, string $file = 'registry.json'): self
    {
        $data = json_decode($json, true);

        // A newer release may record more than this one understands, and
        // saving its file would drop it.
        if (! is_array($data) || ! is_array($data['worktrees'] ?? null) || (int) ($data['version'] ?? 0) > static::$version) {
            throw WorktreeException::unreadableRegistry($file);
        }

        $entries = [];

        foreach ($data['worktrees'] as $entry) {
            if (is_array($entry) && is_string($entry['path'] ?? null) && $entry['path'] !== '') {
                $entries[] = static::normalize($entry);
            }
        }

        return new self($entries);
    }

    /**
     * @return list<Entry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return Entry|null
     */
    public function find(string $path): ?array
    {
        foreach ($this->entries as $entry) {
            if (static::samePath($entry['path'], $path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Records a worktree, replacing an earlier entry for its path. What the
     * earlier run provisioned is kept alongside: a resumed setup run with
     * --no-database still leaves those databases behind for teardown to drop.
     *
     * @param  array<string, mixed>  $entry
     */
    public function put(array $entry): void
    {
        $entry = static::normalize($entry);
        $earlier = $this->find($entry['path']);

        if ($earlier !== null) {
            $entry['databases'] = static::unique([...$earlier['databases'], ...$entry['databases']], fn (array $database): string => $database['dsn'].'|'.$database['database']);
            $entry['files'] = static::unique([...$earlier['files'], ...$entry['files']], fn (array $file): string => $file['path']);
            $entry['created'] = $earlier['created'];
        }

        $this->forget($entry['path']);
        $this->entries[] = $entry;
    }

    public function forget(string $path): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            fn (array $entry): bool => ! static::samePath($entry['path'], $path),
        ));
    }

    public function toJson(): string
    {
        return json_encode(
            ['version' => static::$version, 'worktrees' => $this->entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
    }

    /**
     * Written to a temporary file and renamed into place, so a reader never
     * sees half a file. Windows refuses the rename while another process has
     * the file open, which a command reading the registry does for a moment,
     * so it is tried a few times.
     */
    public function save(string $file): void
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw WorktreeException::unwritableRegistry($file);
        }

        try {
            $json = $this->toJson();
        } catch (JsonException) {
            throw WorktreeException::unwritableRegistry($file);
        }

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json) === false || ! $this->replace($temporary, $file)) {
            @unlink($temporary);

            throw WorktreeException::unwritableRegistry($file);
        }
    }

    protected function replace(string $from, string $to): bool
    {
        foreach ([0, 50_000, 100_000, 250_000, 500_000] as $wait) {
            usleep($wait);

            if (@rename($from, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Paths compare with either slash, and without case where the filesystem
     * ignores it (Windows and macOS).
     */
    protected static function samePath(string $a, string $b): bool
    {
        $a = rtrim(str_replace('\\', '/', $a), '/');
        $b = rtrim(str_replace('\\', '/', $b), '/');

        return in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true)
            ? mb_strtolower($a) === mb_strtolower($b)
            : $a === $b;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return Entry
     */
    protected static function normalize(array $entry): array
    {
        $databases = [];

        foreach (is_array($entry['databases'] ?? null) ? $entry['databases'] : [] as $database) {
            if (is_array($database) && is_string($database['database'] ?? null) && is_string($database['dsn'] ?? null)) {
                $databases[] = [
                    'connection' => (string) ($database['connection'] ?? ''),
                    'database' => $database['database'],
                    'dsn' => $database['dsn'],
                    'test' => (bool) ($database['test'] ?? false),
                ];
            }
        }

        $files = [];

        foreach (is_array($entry['files'] ?? null) ? $entry['files'] : [] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $files[] = ['path' => $file['path'], 'source' => (string) ($file['source'] ?? '')];
            }
        }

        $path = str_replace('\\', '/', (string) $entry['path']);

        // Keys a later release records are kept, so this one never strips
        // them on its next save.
        return [
            ...$entry,
            'path' => $path,
            'name' => (string) ($entry['name'] ?? basename($path)),
            'branch' => is_string($entry['branch'] ?? null) ? $entry['branch'] : null,
            'tool' => (string) ($entry['tool'] ?? 'setup'),
            'driver' => (string) ($entry['driver'] ?? 'none'),
            'secure' => (bool) ($entry['secure'] ?? false),
            'host' => (string) ($entry['host'] ?? ''),
            'url' => is_string($entry['url'] ?? null) ? $entry['url'] : null,
            'databases' => $databases,
            'files' => $files,
            'created' => (string) ($entry['created'] ?? ''),
        ];
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $key
     * @return list<T>
     */
    protected static function unique(array $items, callable $key): array
    {
        $unique = [];

        foreach ($items as $item) {
            $unique[$key($item)] = $item;
        }

        return array_values($unique);
    }
}
