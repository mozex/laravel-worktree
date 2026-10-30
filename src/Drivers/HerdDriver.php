<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Worktree;

/**
 * Serves worktrees through Laravel Herd's CLI.
 */
class HerdDriver extends Driver
{
    public function __construct(protected string $binary = 'herd') {}

    public function available(): bool
    {
        return $this->executable($this->binary);
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        $name = $worktree->name();
        $path = $worktree->path();

        // The site is always linked first. Herd only serves parked and linked
        // directories, and a worktree in a nested path (such as ".worktrees")
        // is neither: "herd secure" alone would mint a certificate for a site
        // that never answers. Linking a parked worktree is harmless.
        $commands = [[$this->binary, 'link', $name]];

        if ($secure) {
            $commands[] = [$this->binary, 'secure'];
        }

        foreach ($commands as $command) {
            if ($runner->run($command, $path)?->successful() === true) {
                continue;
            }

            $runner->warn('Could not run ['.implode(' ', $command).']. The site may need to be served manually.');

            return false;
        }

        if ($this->isServed($name, $path, $runner) === false) {
            $runner->warn("Herd reported linking [{$name}] but does not list it, so the site may not answer. Check the Herd app, then run [{$this->binary} link {$name}] in the worktree.");

            return false;
        }

        return true;
    }

    /**
     * Herd's CLI hands "link" to the Herd app and ignores a failed request, so
     * the command can exit cleanly having linked nothing. Herd's own site list
     * says whether it took; a list that cannot be read proves nothing.
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        $links = $runner->run([$this->binary, 'links'], $path);

        if ($links === null || $links->failed()) {
            return null;
        }

        return str_contains($links->output(), $name);
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        // "herd unsecure" finds the site from its working directory, so it
        // needs the worktree directory to still be there.
        if (! is_dir($path)) {
            return;
        }

        // Setup links the site in every mode, so the link is removed in every
        // mode too.
        if ($secure) {
            $runner->run([$this->binary, 'unsecure'], $path);
        }

        $runner->run([$this->binary, 'unlink', $name], $path);
    }

    /**
     * Herd's CLI asks the Herd app for most of its work over HTTP with no
     * timeout of its own, so a command waits as long as the app does.
     */
    public function timeoutHint(): string
    {
        return 'It may be waiting on a dialog or an elevation prompt; check the Herd app.';
    }
}
