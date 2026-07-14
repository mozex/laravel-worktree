<?php

declare(strict_types=1);

namespace Mozex\Worktree\Enums;

enum FinishMode: string
{
    case PullRequest = 'pr';
    case Merge = 'merge';
    case Abandon = 'abandon';
}
