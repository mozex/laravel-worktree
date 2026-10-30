<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'ddd', 'dump', 'ray', 'die', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('it uses strict types')
    ->expect('Mozex\Worktree')
    ->toUseStrictTypes();

arch('site drivers never start a process themselves')
    ->expect('Mozex\Worktree\Drivers')
    ->not->toUse([
        'Illuminate\Support\Facades\Process',
        'Illuminate\Process\Factory',
        'Symfony\Component\Process\Process',
        'exec',
        'shell_exec',
        'proc_open',
        'passthru',
        'system',
        'popen',
    ]);
