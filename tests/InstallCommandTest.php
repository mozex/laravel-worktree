<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

const STAR_QUESTION = 'Would you like to show some love by starring laravel-worktree on GitHub?';

beforeEach(function (): void {
    Process::fake();

    @unlink($this->app->configPath('worktree.php'));
});

afterEach(function (): void {
    @unlink($this->app->configPath('worktree.php'));
});

function assertOpenedRepository(): void
{
    Process::assertRan(
        fn (PendingProcess $process): bool => in_array('https://github.com/mozex/laravel-worktree', (array) $process->command, true)
    );
}

it('publishes the config file', function (): void {
    $this->artisan('worktree:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('worktree.php')))
        ->toBe(file_get_contents(__DIR__.'/../config/worktree.php'));
});

it('leaves an existing config file alone', function (): void {
    file_put_contents($this->app->configPath('worktree.php'), '<?php return [];');

    $this->artisan('worktree:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->app->configPath('worktree.php')))->toBe('<?php return [];');
});

it('opens the repository when the user agrees to star it', function (): void {
    $this->artisan('worktree:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->doesntExpectOutputToContain('please consider starring')
        ->assertSuccessful();

    assertOpenedRepository();
});

it('opens nothing when the user declines', function (): void {
    $this->artisan('worktree:install')
        ->expectsConfirmation(STAR_QUESTION, 'no')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('prints the repository link when the browser cannot be opened', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    $this->artisan('worktree:install')
        ->expectsConfirmation(STAR_QUESTION, 'yes')
        ->expectsOutputToContain("You'll find laravel-worktree at https://github.com/mozex/laravel-worktree")
        ->assertSuccessful();
});

it('opens the repository with a note instead of asking when nobody can answer', function (): void {
    $this->artisan('worktree:install', ['--no-interaction' => true])
        ->expectsOutputToContain('If laravel-worktree saves you time, please consider starring it on GitHub: https://github.com/mozex/laravel-worktree')
        ->assertSuccessful();

    assertOpenedRepository();

    expect($this->app->configPath('worktree.php'))->toBeFile();
});
