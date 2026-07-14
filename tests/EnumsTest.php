<?php

declare(strict_types=1);

use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Enums\MigrateMode;

it('maps herd modes to a scheme', function () {
    expect(HerdMode::Secure->scheme())->toBe('https')
        ->and(HerdMode::Link->scheme())->toBe('http')
        ->and(HerdMode::None->scheme())->toBe('http');
});

it('knows when herd is enabled', function () {
    expect(HerdMode::Secure->enabled())->toBeTrue()
        ->and(HerdMode::Link->enabled())->toBeTrue()
        ->and(HerdMode::None->enabled())->toBeFalse();
});

it('maps migrate modes to a command', function () {
    expect(MigrateMode::Fresh->command())->toBe('migrate:fresh')
        ->and(MigrateMode::Migrate->command())->toBe('migrate')
        ->and(MigrateMode::None->command())->toBeNull();
});
