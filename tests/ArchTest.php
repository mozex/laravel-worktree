<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'die', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('it uses strict types')
    ->expect('Mozex\Worktree')
    ->toUseStrictTypes();
