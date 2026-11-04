<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Illuminate\Support\Sleep;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Support\Registry;
use Mozex\Worktree\Support\SiteList;
use Mozex\Worktree\Worktree;

/**
 * Serves worktrees through Laravel Herd's CLI.
 *
 * Every command names the site. Without a name, "herd unsecure" and "herd
 * unlink" find the site from their working directory, which is the main
 * repository's own site when run from there, and Herd's own "link --secure"
 * passes the name to "secure" the same way.
 *
 * @phpstan-type Link array{site: string, path: string|null}
 */
class HerdDriver extends Driver
{
    /**
     * How often "herd links" is read after linking, a second apart, before
     * the site counts as not linked: Herd links in the background.
     */
    protected int $checks = 5;

    /**
     * Whether this Herd prints its links as JSON; null until first asked.
     */
    protected ?bool $json = null;

    public function __construct(protected string $binary = 'herd') {}

    public function available(): bool
    {
        return static::executable($this->binary);
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        $name = $worktree->site();
        $path = $worktree->path();

        // The site is always linked before it is secured. Herd only serves
        // parked and linked directories, and a worktree in a nested path (such
        // as ".worktrees") is neither: "herd secure" alone would mint a
        // certificate for a site that never answers. Linking a parked
        // worktree is harmless.
        if (! $this->servesAlready($name, $path, $runner)) {
            if (! $this->attempt($runner, [$this->binary, 'link', $name], $path, 'The site may need to be served manually.')) {
                return false;
            }

            if ($this->waitUntilListed($name, $path, $runner) === false) {
                $commands = "[{$this->binary} link {$name}] in the worktree".($secure ? ", then [{$this->binary} secure {$name}]" : '');
                $runner->warn("Herd reported linking [{$name}] but does not list it, so the site may not answer. Check the Herd app, then run {$commands}.");

                return false;
            }
        }

        return ! $secure || $this->attempt($runner, [$this->binary, 'secure', $name], $path, 'The site may need to be secured manually.');
    }

    /**
     * Herd's CLI hands "link" to the Herd app and ignores a failed request, so
     * the command can exit cleanly having linked nothing. Herd's own list of
     * links says whether it took; a list that cannot be read proves nothing.
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        $links = $this->links($name, $runner);

        return $links === null ? null : $this->find($links, $name) !== null;
    }

    /**
     * Run from the main repository with the name given, so the site is removed
     * even when the worktree directory is already gone. A link under that
     * name to another folder is not the worktree's: another checkout linked
     * the name since, and removing it would take that checkout's site. Herd
     * leaves out a link whose folder is gone, so once the worktree is gone,
     * any link Herd lists under its name is another folder's.
     */
    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        $links = $this->links($name, $runner) ?? [];
        $link = $this->find($links, $name);

        if ($link !== null && $this->linksElsewhere($link, $path)) {
            $runner->warn("Herd serves [{$name}] from [{$link['path']}], not from this worktree, so it is left alone.");
        } else {
            // Herd 2's unlink unsecures the site too; older versions need it asked.
            if ($secure) {
                $runner->run([$this->binary, 'unsecure', $name]);
            }

            $this->attempt($runner, [$this->binary, 'unlink', $name], null, 'The site may need to be removed manually.');
        }

        // Herd 2 can serve a worktree a second time under a site of its own
        // (its Serve button adopts a git worktree as <branch>.<site>), kept in
        // a record of its own: that site is Herd's to remove.
        foreach ($links as $other) {
            if ($other !== $link && $other['path'] !== null && $this->samePath($other['path'], $path)) {
                $runner->warn("Herd also serves this worktree as [{$other['site']}], which this package did not link, so it is left to Herd. Run [{$this->binary} unlink {$other['site']}] to remove it.");
            }
        }
    }

    /**
     * Herd's CLI asks the Herd app for most of its work over HTTP with no
     * timeout of its own, so a command waits as long as the app does.
     */
    public function timeoutHint(): string
    {
        return 'It may be waiting on a dialog or an elevation prompt; check the Herd app.';
    }

    /**
     * Whether a resumed setup finds the site linked to the worktree already.
     * Linking again would let Herd rewrite APP_URL in the worktree's .env and
     * run boost:update in it, and only a worktree an earlier run provisioned
     * has a .env or vendor directory for that to touch, so a fresh one is
     * never asked. A link under the name to another folder is replaced, as a
     * fresh setup would replace it, and said so.
     */
    protected function servesAlready(string $name, string $path, SiteRunner $runner): bool
    {
        if (! is_file($path.'/.env') && ! is_dir($path.'/vendor')) {
            return false;
        }

        $link = $this->find($this->links($name, $runner) ?? [], $name);

        if ($link !== null && $this->linksElsewhere($link, $path)) {
            $runner->warn("Herd serves [{$name}] from [{$link['path']}]; linking it to this worktree instead.");

            return false;
        }

        return $link !== null;
    }

    /**
     * Matches the name alone: right after "herd link" from the worktree, a
     * link under the name is the worktree's.
     */
    protected function waitUntilListed(string $name, string $path, SiteRunner $runner): ?bool
    {
        for ($check = 1; ; $check++) {
            $listed = $this->isServed($name, $path, $runner);

            if ($listed !== false || $check >= $this->checks) {
                return $listed;
            }

            Sleep::for(1)->second();
        }
    }

    /**
     * Herd's links, each with the folder it serves, or null when the list
     * cannot be read. The JSON list is read where Herd has one, and the table
     * where it does not, for the name alone and with no folder.
     *
     * @return list<Link>|null
     */
    protected function links(string $name, SiteRunner $runner): ?array
    {
        if ($this->json !== false) {
            $result = $runner->run([$this->binary, 'links', '--json']);

            if ($result === null) {
                return null;
            }

            $links = $result->successful() ? json_decode(trim($result->output()), true) : null;

            if (is_array($links)) {
                $this->json = true;

                return array_values(array_map(fn (mixed $link): array => [
                    'site' => is_array($link) && is_scalar($link['site'] ?? null) ? (string) $link['site'] : '',
                    'path' => is_array($link) && is_string($link['path'] ?? null) && $link['path'] !== '' ? $link['path'] : null,
                ], $links));
            }

            $this->json = false;
        }

        $result = $runner->run([$this->binary, 'links']);

        if ($result === null || $result->failed()) {
            return null;
        }

        return SiteList::hasCell($result->output(), $name) ? [['site' => $name, 'path' => null]] : [];
    }

    /**
     * @param  list<Link>  $links
     * @return Link|null
     */
    protected function find(array $links, string $name): ?array
    {
        foreach ($links as $link) {
            if (mb_strtolower($link['site']) === mb_strtolower($name)) {
                return $link;
            }
        }

        return null;
    }

    /**
     * Whether the link serves another folder that exists. A link whose folder
     * Herd does not name, or names but is gone, counts as the worktree's, as
     * every link under its name did before: only a live site of another
     * folder is left alone.
     *
     * @param  Link  $link
     */
    protected function linksElsewhere(array $link, string $path): bool
    {
        return $link['path'] !== null && is_dir($link['path']) && ! $this->samePath($link['path'], $path);
    }

    /**
     * Whether two paths are one folder, each resolved while it exists, since
     * Herd on Windows prints backslashes where git and the registry use
     * forward slashes.
     */
    protected function samePath(string $a, string $b): bool
    {
        return Registry::samePath(realpath($a) ?: $a, realpath($b) ?: $b);
    }
}
