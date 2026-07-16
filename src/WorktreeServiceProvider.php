<?php

declare(strict_types=1);

namespace Mozex\Worktree;

use Mozex\Worktree\Commands\ListCommand;
use Mozex\Worktree\Commands\PathCommand;
use Mozex\Worktree\Commands\SetupCommand;
use Mozex\Worktree\Commands\TeardownCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class WorktreeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-worktree')
            ->hasConfigFile()
            ->hasCommands([
                SetupCommand::class,
                TeardownCommand::class,
                PathCommand::class,
                ListCommand::class,
            ]);
    }
}
