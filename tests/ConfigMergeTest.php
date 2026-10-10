<?php

declare(strict_types=1);

use Mozex\Worktree\WorktreeServiceProvider;

/**
 * Runs the provider's config merge in isolation, the same one packageRegistered
 * applies over a shallow-merged published config.
 *
 * @param  array<string, mixed>  $defaults
 * @param  array<string, mixed>  $published
 * @return array<string, mixed>
 */
function mergeWorktreeConfig(array $defaults, array $published): array
{
    $provider = new class(app()) extends WorktreeServiceProvider
    {
        /**
         * @param  array<string, mixed>  $defaults
         * @param  array<string, mixed>  $published
         * @return array<string, mixed>
         */
        public function expose(array $defaults, array $published): array
        {
            return $this->mergeConfig($defaults, $published);
        }
    };

    return $provider->expose($defaults, $published);
}

it('fills a nested key from defaults when the published config omits it', function () {
    // A config published before "connections" existed keeps working: its
    // "database" block overrides what it sets and inherits what it does not.
    $merged = mergeWorktreeConfig(
        ['database' => ['enabled' => true, 'connections' => [['connection' => null]]]],
        ['database' => ['enabled' => false]],
    );

    expect($merged['database']['enabled'])->toBeFalse()
        ->and($merged['database']['connections'])->toBe([['connection' => null]]);
});

it('replaces a list wholesale instead of merging it by index', function () {
    // A user's steps must stay theirs, not pick our defaults back up at the tail.
    $merged = mergeWorktreeConfig(
        ['steps' => ['npm ci', 'npm run build']],
        ['steps' => ['npm install']],
    );

    expect($merged['steps'])->toBe(['npm install']);
});

it('keeps a dependency entry the user deleted out of the merge', function () {
    // Deleting "node_modules" from a published config has to stop npm from
    // running, and an entry the user kept still picks up options added later.
    $merged = mergeWorktreeConfig(
        ['dependencies' => [
            'vendor' => ['copy' => false, 'path' => 'vendor', 'install' => 'composer install'],
            'node_modules' => ['copy' => false, 'path' => 'node_modules', 'install' => 'npm ci'],
        ]],
        ['dependencies' => [
            'vendor' => ['copy' => true, 'install' => 'composer install'],
        ]],
    );

    expect($merged['dependencies'])->toBe([
        'vendor' => ['copy' => true, 'path' => 'vendor', 'install' => 'composer install'],
    ]);
});

it('keeps a dependency entry the user added', function () {
    $merged = mergeWorktreeConfig(
        ['dependencies' => ['vendor' => ['path' => 'vendor']]],
        ['dependencies' => ['vendor' => ['path' => 'vendor'], 'bower' => ['path' => 'bower_components']]],
    );

    expect($merged['dependencies'])->toBe([
        'vendor' => ['path' => 'vendor'],
        'bower' => ['path' => 'bower_components'],
    ]);
});

it('fills in the dependencies of a config published before they existed', function () {
    $merged = mergeWorktreeConfig(
        ['dependencies' => ['vendor' => ['path' => 'vendor']], 'steps' => []],
        ['steps' => ['php artisan storage:link']],
    );

    expect($merged['dependencies'])->toBe(['vendor' => ['path' => 'vendor']]);
});
