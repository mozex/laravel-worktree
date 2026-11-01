<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Support\SiteList;
use Mozex\Worktree\Worktree;

/**
 * Serves worktrees through commands the project configures, for a server
 * with a CLI that has no driver of its own.
 *
 * Each command is a list of arguments, or one string split on its spaces, and
 * its tokens are expanded per argument after the split, so a path holding a
 * space stays one argument. "serve" and "secure" run in the worktree and know
 * every worktree token plus {path}. "check", "unsecure" and "unserve" run from
 * the main repository and know only what teardown does: {name}, {site},
 * {path}, {host} and {tld}. In every command {name} is the site's name, the
 * same as {site}, so a serve and an unserve written with {name} name one
 * site. Any command left empty is skipped.
 */
class CommandDriver extends Driver
{
    /**
     * @param  array<string, mixed>  $commands  The serve, secure, check, unsecure, and unserve commands.
     * @param  string  $tld  The worktree TLD, for {host} and {tld} where there is no worktree.
     */
    public function __construct(
        protected array $commands = [],
        protected string $tld = 'test',
    ) {}

    /**
     * Whether the serve command's program can be found.
     */
    public function available(): bool
    {
        $serve = $this->command('serve');

        return $serve !== [] && static::executable($serve[0]);
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        $name = $worktree->site();
        $path = $worktree->path();
        $tokens = [...$worktree->tokens(), 'name' => $name, 'path' => $this->normalize($path)];

        foreach ($secure ? ['serve', 'secure'] : ['serve'] as $step) {
            $command = $this->command($step, $tokens);

            if ($command !== [] && ! $this->attempt($runner, $command, $path, 'The site may need to be served manually.')) {
                return false;
            }
        }

        if ($this->isServed($name, $path, $runner) === false) {
            $check = implode(' ', $this->command('check', $this->siteTokens($name, $path)));
            $runner->warn("[{$check}] does not list [{$name}], so the site may not answer.");

            return false;
        }

        return true;
    }

    /**
     * The check passes when its output names the site, alone or in a host, a
     * URL or a path; a name that only starts another one does not count.
     *
     * @see SiteList::mentions()
     */
    public function isServed(string $name, string $path, SiteRunner $runner): ?bool
    {
        $command = $this->command('check', $this->siteTokens($name, $path));

        if ($command === []) {
            return null;
        }

        $result = $runner->run($command);

        if ($result === null || $result->failed()) {
            return null;
        }

        return SiteList::mentions($result->output(), $name, $this->tld);
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void
    {
        $tokens = $this->siteTokens($name, $path);

        $unsecure = $secure ? $this->command('unsecure', $tokens) : [];

        if ($unsecure !== []) {
            $runner->run($unsecure);
        }

        $unserve = $this->command('unserve', $tokens);

        // A server whose CLI cannot remove a site still has to lose it.
        if ($unserve === []) {
            $runner->warn("No command is configured to stop serving [{$name}] (worktree.site.drivers.command.unserve), so remove it from the server by hand.");

            return;
        }

        $this->attempt($runner, $unserve, null, 'The site may need to be removed manually.');
    }

    /**
     * One configured command with its tokens expanded.
     *
     * @param  array<string, string>  $tokens
     * @return list<string>
     */
    protected function command(string $step, array $tokens = []): array
    {
        $command = $this->commands[$step] ?? [];

        if (is_string($command)) {
            $command = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($command)) {
            return [];
        }

        $replacements = [];

        foreach ($tokens as $token => $value) {
            $replacements['{'.$token.'}'] = $value;
        }

        return array_values(array_map(
            fn (mixed $argument): string => strtr(is_scalar($argument) ? (string) $argument : '', $replacements),
            $command,
        ));
    }

    /**
     * The tokens a site has without a worktree: teardown may meet one with no
     * branch, known only by its directory.
     *
     * @return array<string, string>
     */
    protected function siteTokens(string $name, string $path): array
    {
        return [
            'name' => $name,
            'site' => $name,
            'path' => $this->normalize($path),
            'host' => $name.'.'.$this->tld,
            'tld' => $this->tld,
        ];
    }

    protected function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
