<?php

declare(strict_types=1);

namespace Mozex\Worktree;

use Illuminate\Support\Arr;

/**
 * A value object describing a single worktree: its branch, on-disk location,
 * Herd host, and the database names derived from the package configuration.
 * It performs no side effects, which keeps the naming rules easy to test.
 */
class Worktree
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected string $sourcePath,
        protected string $branch,
        protected array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(string $sourcePath, string $branch, array $config): self
    {
        return new self(rtrim(str_replace('\\', '/', $sourcePath), '/'), $branch, $config);
    }

    public function sourcePath(): string
    {
        return $this->sourcePath;
    }

    public function repository(): string
    {
        return basename($this->sourcePath);
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function branchSlug(): string
    {
        return str_replace('/', '-', $this->branch);
    }

    public function name(): string
    {
        return str_replace(
            ['{repo}', '{branch}'],
            [$this->repository(), $this->branchSlug()],
            (string) Arr::get($this->config, 'host.template', '{repo}-{branch}'),
        );
    }

    public function tld(): string
    {
        return (string) Arr::get($this->config, 'host.tld', 'test');
    }

    public function host(): string
    {
        return $this->name().'.'.$this->tld();
    }

    public function sourceHost(): string
    {
        return $this->repository().'.'.$this->tld();
    }

    public function path(): string
    {
        $base = (string) Arr::get($this->config, 'path', '..');

        $combined = $this->isAbsolute($base)
            ? $base.'/'.$this->name()
            : $this->sourcePath.'/'.$base.'/'.$this->name();

        return $this->normalize($combined);
    }

    /**
     * Maps a path inside the source repository onto the same place inside the
     * worktree. Returns null for anything outside the repository, which the
     * worktree is meant to keep sharing.
     */
    public function mapPath(string $path): ?string
    {
        $normalized = $this->normalize($path);

        if ($normalized === $this->sourcePath) {
            return $this->path();
        }

        if (! str_starts_with($normalized, $this->sourcePath.'/')) {
            return null;
        }

        return $this->path().mb_substr($normalized, mb_strlen($this->sourcePath));
    }

    public function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:/', $path);
    }

    public function slug(): string
    {
        return mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->name()));
    }

    public function appDatabase(): string
    {
        return str_replace(
            '{slug}',
            $this->slug(),
            (string) Arr::get($this->config, 'database.name', '{slug}'),
        );
    }

    public function testDatabase(): string
    {
        return $this->appDatabase().(string) Arr::get($this->config, 'database.test.suffix', '_testing');
    }

    protected function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = '';

        if (preg_match('/^([A-Za-z]:)?\//', $path, $matches) === 1) {
            $prefix = $matches[0];
            $path = mb_substr($path, mb_strlen($prefix));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }
}
