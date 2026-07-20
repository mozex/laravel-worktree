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
        // The host template is not expanded through expand(): it feeds name(),
        // which the {name}, {slug}, and {host} tokens all derive from, so it can
        // only know the two tokens that come before a name exists.
        return $this->replaceTokens(
            (string) Arr::get($this->config, 'host.template', '{repo}-{branch}'),
            ['repo' => $this->repository(), 'branch' => $this->branchSlug()],
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

    /**
     * Expands the worktree tokens in a template, plus any per-call extras such
     * as the {value} an env replacement carries. This is the one vocabulary
     * every configurable template shares (the database name and the env.replace
     * map), so a token means the same thing wherever it is written.
     *
     * @param  array<string, string>  $extra
     */
    public function expand(string $template, array $extra = []): string
    {
        return $this->replaceTokens($template, array_merge($this->tokens(), $extra));
    }

    /**
     * The tokens a template may use. None of these read a configurable template
     * back (appDatabase() is deliberately absent), so expand() cannot recurse.
     *
     * @return array<string, string>
     */
    public function tokens(): array
    {
        return [
            'repo' => $this->repository(),
            'branch' => $this->branchSlug(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'host' => $this->host(),
            'tld' => $this->tld(),
        ];
    }

    public function appDatabase(): string
    {
        $name = $this->expand((string) Arr::get($this->config, 'database.name', '{slug}'));

        return $this->fitDatabase($name, $this->maxDatabaseLength() - mb_strlen($this->testSuffix()));
    }

    /**
     * Postgres truncates identifiers to 63 bytes (so a longer name could never
     * be connected to again) and MySQL rejects names over 64 outright. The
     * test database must fit too, since it is the app name plus a suffix.
     */
    protected function maxDatabaseLength(): int
    {
        return 63;
    }

    public function testDatabase(): string
    {
        return $this->appDatabase().$this->testSuffix();
    }

    protected function testSuffix(): string
    {
        return (string) Arr::get($this->config, 'database.test.suffix', '_testing');
    }

    /**
     * A long repository plus a long branch overruns the server's identifier
     * limit, so the name is cut and given a short hash of what it really was,
     * keeping two truncated branches from colliding on one database.
     */
    protected function fitDatabase(string $name, int $limit): string
    {
        if (mb_strlen($name) <= $limit) {
            return $name;
        }

        $hash = substr(md5($name), 0, 6);

        return mb_substr($name, 0, $limit - 7).'_'.$hash;
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function replaceTokens(string $template, array $map): string
    {
        $tokens = array_map(fn (string $token): string => '{'.$token.'}', array_keys($map));

        return str_replace($tokens, array_values($map), $template);
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
