<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Illuminate\Support\Sleep;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Support\SiteList;
use Mozex\Worktree\Worktree;

/**
 * Serves worktrees through Laravel Herd's CLI.
 *
 * Every command names the site. Without a name, "herd unsecure" and "herd
 * unlink" find the site from their working directory, which is the main
 * repository's own site when run from there, and Herd's own "link --secure"
 * passes the name to "secure" the same way.
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
        $name = $worktree->name();
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
     * The JSON list is read where Herd has one, and the table where it does not.
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        if ($this->json !== false) {
            $result = $runner->run([$this->binary, 'links', '--json']);

            if ($result === null) {
                return null;
            }

            $links = $result->successful() ? json_decode(trim($result->output()), true) : null;

            if (is_array($links)) {
                $this->json = true;

                return in_array(mb_strtolower($name), array_map(
                    fn (mixed $site): string => mb_strtolower(is_scalar($site) ? (string) $site : ''),
                    array_column($links, 'site'),
                ), true);
            }

            $this->json = false;
        }

        $result = $runner->run([$this->binary, 'links']);

        if ($result === null || $result->failed()) {
            return null;
        }

        return SiteList::hasCell($result->output(), $name);
    }

    /**
     * Run from the main repository with the name given, so the site is removed
     * even when the worktree directory is already gone.
     */
    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        // Herd 2's unlink unsecures the site too; older versions need it asked.
        if ($secure) {
            $runner->run([$this->binary, 'unsecure', $name]);
        }

        $this->attempt($runner, [$this->binary, 'unlink', $name], null, 'The site may need to be removed manually.');
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
     * Whether a resumed setup finds the site linked already. Linking again
     * would let Herd rewrite APP_URL in the worktree's .env and run
     * boost:update in it, and only a worktree an earlier run provisioned has a
     * .env or vendor directory for that to touch, so a fresh one is never asked.
     */
    protected function servesAlready(string $name, string $path, SiteRunner $runner): bool
    {
        if (! is_file($path.'/.env') && ! is_dir($path.'/vendor')) {
            return false;
        }

        return $this->isServed($name, $path, $runner) === true;
    }

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
}
