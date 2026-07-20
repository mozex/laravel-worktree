<?php

declare(strict_types=1);

namespace Mozex\Worktree;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
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

    /**
     * Laravel merges a published config with a shallow array_merge, so a user's
     * "database" block would replace ours wholesale and silently lose keys we
     * add later, such as a new connection. Re-merge here after that shallow
     * pass: nested option groups fill in from the defaults, while a list the
     * user set (steps, connections) stays their own.
     */
    public function packageRegistered(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var Repository $config */
        $config = $this->app->make('config');

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/worktree.php';

        /** @var array<string, mixed> $published */
        $published = $config->get('worktree', []);

        $config->set('worktree', $this->mergeConfig($defaults, $published));
    }

    /**
     * Deep-merge maps, replace lists. A nested option group fills in from the
     * defaults, while a sequential list the user provided is kept as theirs
     * rather than being index-merged with ours (which would leak default steps
     * or scramble connection entries back in).
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    protected function mergeConfig(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $recurse = is_array($value) && ! array_is_list($value)
                && isset($defaults[$key]) && is_array($defaults[$key]) && ! array_is_list($defaults[$key]);

            /** @var array<string, mixed> $default */
            $default = $recurse ? $defaults[$key] : [];

            /** @var array<string, mixed> $override */
            $override = $recurse ? $value : [];

            $defaults[$key] = $recurse ? $this->mergeConfig($default, $override) : $value;
        }

        return $defaults;
    }
}
