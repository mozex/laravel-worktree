<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Mozex\Worktree\Support\ClaudeSettings;

/**
 * Writes Claude Code's WorktreeCreate and WorktreeRemove hooks, which run
 * worktree:hook, into the project's local settings. Never the shared
 * .claude/settings.json: a WorktreeCreate hook replaces how Claude makes every
 * worktree, which is each developer's own choice.
 */
class InstallAgentCommand extends WorktreeCommand
{
    protected $signature = 'worktree:install-agent
        {agent : The agent to install the hooks for: claude}
        {--base= : The branch new worktrees start from (default: the configured base branch, else the branch checked out here)}
        {--secure : Leave HTTPS to site.secure, as setup does, instead of serving each worktree over http}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}
        {--timeout=1800 : Seconds Claude Code gives each hook before stopping it}';

    protected $description = 'Set up an agent tool so the worktrees it makes get their own databases, .env and site';

    /**
     * The hook's options that pass on what setup does.
     *
     * @var list<string>
     */
    protected array $provisioning = ['no-database', 'no-migrate', 'no-install', 'seed', 'clone', 'no-clone'];

    public function handle(): int
    {
        $source = $this->laravel->basePath();

        if (! $this->isGitRepository($source)) {
            $this->components->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->components->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $agent = (string) $this->argument('agent');

        if ($agent !== 'claude') {
            $this->components->error("worktree:install-agent sets up Claude Code, so the agent is claude, not [{$agent}].");

            return self::FAILURE;
        }

        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($timeout === false) {
            $this->components->error('The --timeout is a number of seconds.');

            return self::FAILURE;
        }

        // Claude Code spawns the hook with no shell, which on Windows cannot
        // start a .bat shim, so the hook runs the PHP running this command.
        if (! is_file(PHP_BINARY)) {
            $this->components->error('Could not tell which PHP runs this command, so there is nothing to start the hook with.');

            return self::FAILURE;
        }

        $artisan = realpath($source.'/artisan');

        if ($artisan === false) {
            $this->components->error("[{$source}] has no artisan file for the hook to run.");

            return self::FAILURE;
        }

        $base = $this->base($source);

        if ($base === null) {
            return self::FAILURE;
        }

        $local = $source.'/.claude/settings.local.json';
        $settings = $this->settingsFile($source, $local);

        if ($settings === null) {
            return self::FAILURE;
        }

        $foreign = $settings->foreignEvents();

        if ($foreign !== []) {
            $this->components->error('[.claude/settings.local.json] already has a '.implode(' and a ', $foreign).' hook of its own. Claude Code runs every one it finds, so two would set up or remove the same worktree at once; remove it first.');

            return self::FAILURE;
        }

        $profile = $this->profile();

        $settings->install([
            'WorktreeCreate' => $this->handler($artisan, ['create', '--base='.$base, ...$profile], $timeout, 'Setting up the worktree'),
            'WorktreeRemove' => $this->handler($artisan, ['remove'], $timeout, 'Finishing the worktree'),
        ]);

        if (! $settings->save($local)) {
            $this->components->error("Could not write [{$local}].");

            return self::FAILURE;
        }

        $this->components->info("Claude Code's worktree hooks are in [.claude/settings.local.json].");

        $this->components->twoColumnDetail('Worktrees branch off', $base);
        $this->components->twoColumnDetail('Setup options', implode(' ', $profile) ?: 'none');
        $this->components->twoColumnDetail('Hook time limit', $timeout.' seconds');

        $this->warnIfShared($source);

        // A WorktreeCreate hook replaces Claude's own git worktree add, and
        // the file copying that came with it.
        if (is_file($source.'/.worktreeinclude')) {
            $this->components->warn('Claude Code no longer reads .worktreeinclude now that a hook makes its worktrees, so the files it lists stop reaching them. Gitignored env files go in the env.copy setting instead.');
        }

        $this->components->warn("Claude Code never removes a subagent's worktree. worktree:list shows them, and worktree:teardown finishes each one.");

        return self::SUCCESS;
    }

    /**
     * The settings hold paths on this machine, so they stay out of commits.
     */
    protected function warnIfShared(string $source): void
    {
        if ($this->attempt(['git', 'ls-files', '--error-unmatch', '.claude/settings.local.json'], $source)) {
            $this->components->warn('[.claude/settings.local.json] is committed to this repository, and it holds paths on this machine. Untrack it with git rm --cached, and add it to your .gitignore.');

            return;
        }

        if (! $this->attempt(['git', 'check-ignore', '-q', '.claude/settings.local.json'], $source)) {
            $this->components->warn('[.claude/settings.local.json] is not gitignored. It holds paths on this machine, so add it to your .gitignore.');
        }
    }

    /**
     * The branch the hook's new worktrees start from: --base, else the
     * configured base branch when this repository has it, else the branch
     * checked out here, so a repository on master doesn't fail every create.
     */
    protected function base(string $source): ?string
    {
        $base = (string) $this->option('base');

        if ($base !== '') {
            if ($this->resolves($base, $source)) {
                return $base;
            }

            $this->components->error("[{$base}] is not a branch or commit in this repository.");

            return null;
        }

        $configured = (string) Arr::get($this->settings(), 'base_branch', 'main');

        if ($configured !== '' && $this->resolves($configured, $source)) {
            return $configured;
        }

        $current = trim($this->capture(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'], $source));

        if ($current === '') {
            $this->components->error("The configured base branch [{$configured}] isn't in this repository, and no branch is checked out here. Pass --base.");

            return null;
        }

        $this->components->warn("The configured base branch [{$configured}] isn't in this repository, so new worktrees branch off [{$current}], the branch checked out here. Pass --base to choose another.");

        return $current;
    }

    protected function resolves(string $ref, string $source): bool
    {
        return $this->attempt(['git', 'rev-parse', '--verify', '--quiet', $ref.'^{commit}'], $source);
    }

    /**
     * The local settings, refused when Claude Code's shared settings already
     * hook its worktrees: Claude runs the hooks of every settings file, and
     * two WorktreeCreate hooks would each make a worktree.
     */
    protected function settingsFile(string $source, string $local): ?ClaudeSettings
    {
        $shared = ClaudeSettings::fromFile($source.'/.claude/settings.json');

        if ($shared === null) {
            $this->components->error('Could not read [.claude/settings.json] as Claude Code settings, so whether it hooks worktrees already is unknown.');

            return null;
        }

        $hooked = $shared->hookedEvents();

        if ($hooked !== []) {
            $this->components->error('[.claude/settings.json] already has a '.implode(' and a ', $hooked).' hook, and Claude Code runs every one it finds. Remove it there, or keep that one instead.');

            return null;
        }

        $settings = ClaudeSettings::fromFile($local);

        if ($settings === null) {
            $this->components->error('Could not read [.claude/settings.local.json] as Claude Code settings, so it is left as it is.');
        }

        return $settings;
    }

    /**
     * What the hook passes on to setup: http unless --secure, which leaves
     * HTTPS to site.secure, and the provisioning options given here.
     *
     * @return list<string>
     */
    protected function profile(): array
    {
        $options = $this->option('secure') ? [] : ['--no-secure'];

        foreach ($this->provisioning as $option) {
            if ($this->option($option)) {
                $options[] = '--'.$option;
            }
        }

        return $options;
    }

    /**
     * Each hook in exec form, with no shell to quote for, and display_errors
     * on stderr, so a PHP warning never lands on stdout, where Claude reads
     * the worktree's path.
     *
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    protected function handler(string $artisan, array $arguments, int $timeout, string $status): array
    {
        return [
            'type' => 'command',
            'command' => PHP_BINARY,
            'args' => ['-d', 'display_errors=stderr', $artisan, 'worktree:hook', 'claude', ...$arguments],
            'timeout' => $timeout,
            'statusMessage' => $status,
        ];
    }
}
