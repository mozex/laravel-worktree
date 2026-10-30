<?php

declare(strict_types=1);

namespace Mozex\Worktree\Drivers;

use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Worktree;

/**
 * Serves nothing, for a site served some other way or not at all.
 */
class NoneDriver extends Driver
{
    public function url(Worktree $worktree, bool $secure): ?string
    {
        return null;
    }

    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        return true;
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void {}
}
