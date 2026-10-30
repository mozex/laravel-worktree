<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Mozex\Worktree\Support\ProcessSiteRunner;

/**
 * @param  list<string>  $warnings
 * @param  array<string, false>  $environment
 */
function siteRunner(array &$warnings, int $timeout = 60, array $environment = []): ProcessSiteRunner
{
    return new ProcessSiteRunner(
        sys_get_temp_dir(),
        $environment,
        $timeout,
        'Check the app.',
        function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        },
    );
}

it('runs a command with the main app environment unset, its time limit, and the main repository as default directory', function () {
    Process::fake();
    $warnings = [];

    $runner = siteRunner($warnings, 7, ['APP_KEY' => false]);
    $runner->run(['herd', 'links']);
    $runner->run(['herd', 'link', 'blog-feature'], '/sites/blog-feature');

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['herd', 'links']
        && $process->path === sys_get_temp_dir()
        && $process->environment === ['APP_KEY' => false]
        && $process->timeout === 7);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['herd', 'link', 'blog-feature']
        && $process->path === '/sites/blog-feature');
});

it('returns a failed command for the driver to judge, without a warning', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $warnings = [];

    expect(siteRunner($warnings)->run(['herd', 'link'])?->failed())->toBeTrue()
        ->and($warnings)->toBe([]);
});

it('stops a command that runs out of time and skips the ones after it', function () {
    $warnings = [];
    $runner = siteRunner($warnings, 1);
    $started = microtime(true);

    expect($runner->run([PHP_BINARY, '-r', 'sleep(20);']))->toBeNull()
        ->and($runner->run([PHP_BINARY, '-r', 'echo "ran";']))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(15)
        ->and($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('did not finish within 1 seconds. Check the app.')
        ->and($warnings[1])->toContain('Skipped [')->toContain('echo "ran";');
});

it('reports a command that cannot start instead of throwing', function () {
    $warnings = [];
    $runner = siteRunner($warnings);

    expect($runner->run([PHP_BINARY, '-v'], sys_get_temp_dir().'/wt-gone-'.bin2hex(random_bytes(4))))->toBeNull()
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Could not start')
        ->and($runner->run([PHP_BINARY, '-r', 'echo "ran";'])?->output())->toBe('ran');
});

it('reports a missing binary as a failed command', function () {
    $warnings = [];

    $result = siteRunner($warnings)->run(['wt-missing-site-tool', 'link']);

    expect($result === null || $result->failed())->toBeTrue();
});
