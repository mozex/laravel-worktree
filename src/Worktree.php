<?php

declare(strict_types=1);

namespace Mozex\Worktree;

use Illuminate\Support\Arr;

/**
 * A value object describing a single worktree: its branch, on-disk location,
 * site host, and the database names derived from the package configuration.
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
        protected ?string $fixedName = null,
        protected ?string $fixedPath = null,
        protected ?string $fixedSite = null,
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
        return $this->fixedName ?? $this->fitName($this->uncappedName());
    }

    /**
     * The name before the length cap, which is what worktrees made before the
     * cap existed were named after.
     */
    public function uncappedName(): string
    {
        // The host template is not expanded through expand(): it feeds name(),
        // which the {name}, {slug}, and {host} tokens all derive from, so it can
        // only know the two tokens that come before a name exists.
        return $this->replaceTokens(
            (string) Arr::get($this->config, 'host.template', '{repo}-{branch}'),
            ['repo' => $this->repository(), 'branch' => $this->branchSlug()],
        );
    }

    /**
     * The same worktree under a name it already has on disk, so an existing
     * worktree keeps its directory, host, and database names.
     */
    public function withName(string $name): self
    {
        return new self($this->sourcePath, $this->branch, $this->config, $name, $this->fixedPath, $this->fixedSite);
    }

    /**
     * The same worktree in a directory another tool chose, which the
     * configured path and name no longer describe.
     */
    public function withPath(string $path): self
    {
        return new self($this->sourcePath, $this->branch, $this->config, $this->fixedName, $this->normalize($path), $this->fixedSite);
    }

    /**
     * The same worktree under the site it was first served as, so a server
     * already serving it never gets it again under another name.
     */
    public function withSite(string $site): self
    {
        return new self($this->sourcePath, $this->branch, $this->config, $this->fixedName, $this->fixedPath, $site);
    }

    public function tld(): string
    {
        return (string) Arr::get($this->config, 'host.tld', 'test');
    }

    /**
     * The name the server knows the site by, which the TLD follows to make the
     * host: the site template expanded, then each part between dots lowercased
     * with anything but letters and digits turned into a hyphen, since that is
     * all a hostname may hold. The worktree's name keeps its own characters
     * for the directory and the databases.
     */
    public function site(): string
    {
        if ($this->fixedSite !== null) {
            return $this->fixedSite;
        }

        $template = Arr::get($this->config, 'host.site');

        $site = $this->label($this->replaceTokens(
            is_string($template) && trim($template) !== '' ? $template : '{name}',
            ['repo' => $this->repository(), 'branch' => $this->branchSlug(), 'name' => $this->name(), 'slug' => $this->slug()],
        ));

        // A branch made only of characters a host cannot hold leaves nothing
        // of itself, or only the repository's name, which is the main site's:
        // served there, the worktree would take the main site over.
        if ($site === '' || $site === mb_strtolower($this->repository())) {
            $site = ltrim($this->label($this->repository()).'-'.substr(md5($this->name()), 0, 6), '-');
        }

        return $this->fitSite($site);
    }

    public function host(): string
    {
        return $this->site().'.'.$this->tld();
    }

    /**
     * Whether the worktree is served under the main site's host, as
     * "feature.blog.test" is under "blog.test", where the main site's cookies
     * can reach it.
     */
    public function isUnderSourceHost(): bool
    {
        return str_ends_with(mb_strtolower($this->host()), '.'.mb_strtolower($this->sourceHost()));
    }

    public function sourceHost(): string
    {
        return $this->repository().'.'.$this->tld();
    }

    public function path(): string
    {
        if ($this->fixedPath !== null) {
            return $this->fixedPath;
        }

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
            'site' => $this->site(),
            'host' => $this->host(),
            'tld' => $this->tld(),
        ];
    }

    /**
     * A worktree database name from a template: the tokens are expanded and the
     * result is fit within the server's identifier limit. Every database this
     * package provisions (application or test, on any connection) goes through
     * here, so the naming rules stay in one place.
     */
    public function database(string $template): string
    {
        return $this->fitDatabase($this->expand($template), $this->maxDatabaseLength());
    }

    /**
     * Postgres truncates identifiers to 63 bytes (so a longer name could never
     * be connected to again) and MySQL rejects names over 64 outright.
     */
    protected function maxDatabaseLength(): int
    {
        return 63;
    }

    /**
     * A long repository plus a long branch overruns the server's identifier
     * limit, so the name is cut and given a short hash of what it really was,
     * keeping two truncated branches from colliding on one database.
     */
    protected function fitDatabase(string $name, int $limit): string
    {
        return $this->fit($name, $limit, '_');
    }

    /**
     * The name is the site's by default, and a host has to fit in a TLS
     * certificate's common name, which holds 64 characters at most (and in a
     * DNS label, which holds 63). Securing the site fails on anything longer, so
     * the name is capped to leave the host within 63 characters, with the same
     * cut-and-hash as a database name. Every other name (the directory, the
     * database slug) derives from this one, so they all stay in step. A
     * worktree made before the cap keeps its uncapped name, so this cap is
     * never moved: a site template gets its own (fitSite()).
     */
    protected function fitName(string $name): string
    {
        return $this->fit($name, 63 - mb_strlen('.'.$this->tld()), '-');
    }

    /**
     * The host has the same certificate limit as the name, so the site is
     * capped to keep it within 63 characters. A site under another host keeps
     * that host whole and is cut in front of it: the main site's
     * ("release-2.3.blog" keeps ".blog"), or else whatever follows its first
     * part.
     */
    protected function fitSite(string $site): string
    {
        $limit = 63 - strlen('.'.$this->tld());

        if (strlen($site) <= $limit) {
            return $site;
        }

        $hash = substr(md5($site), 0, 6);
        $main = '.'.$this->label($this->repository());
        $dot = strpos($site, '.');

        $kept = match (true) {
            $main !== '.' && str_ends_with($site, $main) => $main,
            $dot !== false => substr($site, $dot),
            default => '',
        };

        $room = $limit - strlen($kept);

        if ($kept !== '' && $room >= 8) {
            return rtrim(substr($site, 0, $room - 7), '.-').'-'.$hash.$kept;
        }

        return rtrim(substr($site, 0, $limit - 7), '.-').'-'.$hash;
    }

    /**
     * Each part between dots lowercased, with every run of anything but a
     * letter or digit turned into one hyphen and none left at either end, and
     * empty parts dropped.
     */
    protected function label(string $value): string
    {
        $labels = array_map(
            fn (string $label): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', $label), '-'),
            explode('.', mb_strtolower($value)),
        );

        return implode('.', array_filter($labels, fn (string $label): bool => $label !== ''));
    }

    protected function fit(string $name, int $limit, string $separator): string
    {
        if (mb_strlen($name) <= $limit) {
            return $name;
        }

        $hash = substr(md5($name), 0, 6);

        return mb_substr($name, 0, $limit - 7).$separator.$hash;
    }

    /**
     * @param  array<string, string>  $map
     */
    protected function replaceTokens(string $template, array $map): string
    {
        $tokens = array_map(fn (string $token): string => '{'.$token.'}', array_keys($map));

        return str_replace($tokens, array_values($map), $template);
    }

    public function normalize(string $path): string
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
