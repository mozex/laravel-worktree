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
