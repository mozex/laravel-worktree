<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use JsonException;
use Mozex\Worktree\Support\ClaudeSettings;
use Mozex\Worktree\Support\ParadevConfig;
use Mozex\Worktree\Support\SettingsFile;

/**
 * Sets up a tool that makes git worktrees so each one gets its own databases,
 * .env and site. Claude Code gets its WorktreeCreate and WorktreeRemove hooks,
 * which run worktree:hook, in the project's local settings, never the shared
 * .claude/settings.json: a WorktreeCreate hook replaces how Claude makes every
 * worktree, which is each developer's own choice. git gets a post-checkout
 * hook that adopts each worktree git worktree add makes, whatever ran it. The
 * other tools run the published worktree-adopt.php in each new worktree:
 * written into the tool's local config when it has one, else printed for the
 * file the team shares or the app's settings.
 */
class InstallAgentCommand extends WorktreeCommand
{
    protected $signature = 'worktree:install-agent
        {agent : The tool to set up: claude, git, conductor, paradev, cursor, codex, zed or devin}
        {--base= : The branch new worktrees start from (Claude Code only; default: the configured base branch, else the branch checked out here)}
        {--secure : Leave HTTPS to site.secure, as setup does, instead of serving each worktree over http}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}
        {--timeout=1800 : Seconds Claude Code gives each hook before stopping it (Claude Code only)}';

    protected $description = 'Set up an agent tool so the worktrees it makes get their own databases, .env and site';

    /**
     * Every tool this command sets up.
     *
     * @var list<string>
     */
    protected array $agents = ['claude', 'git', 'conductor', 'paradev', 'cursor', 'codex', 'zed', 'devin'];

    /**
     * The tools that turn git's hooks off when they make a worktree, so the
     * git hook never adopts their worktrees a second time.
     *
     * @var list<string>
     */
    protected array $hookless = ['cursor', 'codex'];

    /**
     * The line that marks a post-checkout hook as the one this command wrote.
     */
    protected string $gitHookMarker = '# Written by php artisan worktree:install-agent git';

    /**
     * The line that marks Conductor's local settings as the ones this command
     * wrote.
     */
    protected string $conductorMarker = '# Written by php artisan worktree:install-agent conductor';

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

        if (! in_array($agent, $this->agents, true)) {
            $this->components->error('worktree:install-agent sets up '.Arr::join($this->agents, ', ', ' or ').", not [{$agent}].");

            return self::FAILURE;
        }

        // Claude Code spawns its hooks with no shell, which on Windows cannot
        // start a .bat shim, so its hooks and git's run the PHP running this
        // command.
        if (in_array($agent, ['claude', 'git'], true) && ! is_file(PHP_BINARY)) {
            $this->components->error('Could not tell which PHP runs this command, so there is nothing to start the hook with.');

            return self::FAILURE;
        }

        $artisan = realpath($source.'/artisan');

        if ($artisan === false) {
            $this->components->error("[{$source}] has no artisan file for the hook to run.");

            return self::FAILURE;
        }

        return match ($agent) {
            'claude' => $this->installClaude($source, $artisan),
            'git' => $this->installGit($source, $artisan),
            'conductor' => $this->installConductor($source),
            'paradev' => $this->installParadev($source),
            default => $this->printRecipe($source, $agent),
        };
    }

    protected function installClaude(string $source, string $artisan): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($timeout === false) {
            $this->components->error('The --timeout is a number of seconds.');

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

        $this->warnIfShared($source, '.claude/settings.local.json', 'It holds paths on this machine');

        // A WorktreeCreate hook replaces Claude's own git worktree add, and
        // the file copying that came with it.
        if (is_file($source.'/.worktreeinclude')) {
            $this->components->warn('Claude Code no longer reads .worktreeinclude now that a hook makes its worktrees, so the files it lists stop reaching them. Gitignored env files go in the env.copy setting instead.');
        }

        $this->components->warn("Claude Code never removes a subagent's worktree. worktree:list shows them, and worktree:teardown finishes each one.");

        return self::SUCCESS;
    }

    /**
     * A post-checkout hook in this repository's own git directory, which no
     * commit carries and no other repository reads. One another tool wrote
     * there (git-lfs writes one) is left as it is.
     */
    protected function installGit(string $source, string $artisan): int
    {
        $options = ['--tool=git', '--unless-set-up', ...$this->profile()];
        $directories = $this->hooksDirectory($source, $artisan, $options);

        if ($directories === null) {
            return self::FAILURE;
        }

        [$common, $hooks] = $directories;
        $file = $hooks.'/post-checkout';

        if (is_file($file) && ! str_contains((string) @file_get_contents($file), $this->gitHookMarker)) {
            $this->components->error("[{$file}] is a post-checkout hook of its own, so it is left as it is. To adopt each new worktree, add these lines at the end of it:");
            $this->printGitHookCommands($common, $source, $artisan, $options);

            return self::FAILURE;
        }

        // A half-written hook would fail every checkout, so it is renamed
        // into place.
        if (! SettingsFile::write($file, $this->gitHook($common, $source, $artisan, $options))) {
            $this->components->error("Could not write [{$file}].");

            return self::FAILURE;
        }

        @chmod($file, 0755);

        $this->components->info("Each worktree git worktree add makes is now adopted, through the post-checkout hook at [{$file}].");

        $this->components->twoColumnDetail('Adopt options', implode(' ', $options));

        $this->components->warn("worktree:setup and Claude Code's hook skip it for the worktrees they make. A tool whose own setup script adopts its worktrees would set each one up twice, so use one or the other.");

        $tools = array_filter(['.conductor', 'conductor.json', '.superset'], fn (string $path): bool => file_exists($source.'/'.$path));

        if ($tools !== []) {
            $this->components->warn('['.implode('] and [', $tools)."] hold a tool's settings. git runs this hook inside that tool's git worktree add, before the tool's own setup, so a setup that copies .env points the worktree back at your main database. Set such a tool up with worktree:install-agent and its name instead.");
        }

        if (preg_match('/^\s*(\/|\*\*\/)?\.env\b/m', (string) @file_get_contents($source.'/.worktreeinclude')) === 1) {
            $this->components->warn('[.worktreeinclude] lists .env, so Claude Code copies it into the worktrees it makes itself once git has made them, which may replace the .env adopt wrote. For Claude Code, run worktree:install-agent claude instead.');
        }

        return self::SUCCESS;
    }

    /**
     * Conductor's local settings, which take the place of the repository's
     * shared ones on this machine. Written whole, so only a file this command
     * wrote before is replaced.
     */
    protected function installConductor(string $source): int
    {
        if (! $this->publishShim($source)) {
            return self::FAILURE;
        }

        $relative = '.conductor/settings.local.toml';
        $file = $source.'/'.$relative;
        $command = $this->shimCommand('conductor');
        $setup = 'setup = "'.addcslashes($command, '"\\').'"';

        if (is_file($file) && ! str_starts_with((string) @file_get_contents($file), $this->conductorMarker)) {
            $this->components->error("[{$relative}] has settings of its own, so it is left as it is. Add this under [scripts] in it:");
            $this->line($setup);

            return self::FAILURE;
        }

        $contents = implode("\n", [
            $this->conductorMarker.' (mozex/laravel-worktree).',
            '# Run that command again to change this file; it replaces what is here.',
            '[scripts]',
            $setup,
            '',
        ]);

        if (! SettingsFile::write($file, $contents)) {
            $this->components->error("Could not write [{$file}].");

            return self::FAILURE;
        }

        $this->components->info("Conductor now runs worktree-adopt.php in each new workspace, through [{$relative}].");

        $this->components->twoColumnDetail('Setup command', $command);

        $this->warnIfShared($source, $relative, "It is your own setup, not the team's");

        if ($this->hasConductorSetup($source)) {
            $this->components->warn("Conductor runs [{$relative}]'s setup in place of the repository's own setup script, which no longer runs for you. Add what it does that adopt doesn't to the setup line, before worktree-adopt.php.");
        }

        $this->components->warn("Conductor copies .env files into each workspace and starts its agent without waiting for setup, so until adopt has run, that agent's .env points at your main database. Leave .env out of Conductor's files to copy.");

        $this->warnIfGitHook($source, 'conductor');

        return self::SUCCESS;
    }

    /**
     * Paradev's (formerly Superset's) local config, its setup run after the
     * repository's own setup commands, which may copy .env for adopt to then
     * rewrite.
     */
    protected function installParadev(string $source): int
    {
        if (! $this->publishShim($source)) {
            return self::FAILURE;
        }

        $command = $this->shimCommand('paradev');

        // Paradev reads no JSON config at all once a TOML one is there.
        foreach (['.superset/config.toml', '.superset/config.local.toml'] as $toml) {
            if (is_file($source.'/'.$toml)) {
                $this->components->error("[{$toml}] is there, so Paradev reads no JSON config, and nothing is written. Add this command last in the setup run list of [.superset/config.local.toml]:");
                $this->line($command);

                return self::FAILURE;
            }
        }

        $relative = '.superset/config.local.json';
        $file = $source.'/'.$relative;
        $config = ParadevConfig::fromFile($file);

        if ($config === null) {
            $this->components->error("Could not read [{$relative}] as Paradev config, so it is left as it is.");

            return self::FAILURE;
        }

        $config->install($command);

        if (! $config->save($file)) {
            $this->components->error("Could not write [{$file}].");

            return self::FAILURE;
        }

        $this->components->info("Paradev now runs worktree-adopt.php in each new workspace after its other setup commands, through [{$relative}].");

        $this->components->twoColumnDetail('Setup command', $command);

        $this->warnIfShared($source, $relative, "It is your own setup, not the team's");

        $this->components->warn('Paradev starts a new workspace\'s agent alongside its setup, before the worktree has its own .env and databases. Turn on Settings > Experimental > "Wait for workspace setup before starting agents".');

        $this->warnIfGitHook($source, 'paradev');

        return self::SUCCESS;
    }

    /**
     * What to add for a tool whose worktree setup lives in a file the team
     * shares or in the app's own settings, which this command doesn't write.
     */
    protected function printRecipe(string $source, string $tool): int
    {
        if (! $this->publishShim($source)) {
            return self::FAILURE;
        }

        $command = $this->shimCommand($tool);

        try {
            [$where, $recipe, $notes] = $this->recipe($source, $tool, $command);
        } catch (JsonException) {
            $this->components->error("Could not write out the setup for {$tool}.");

            return self::FAILURE;
        }

        $this->components->info("Add this to {$where}:");
        $this->line($recipe);

        foreach ($notes as $note) {
            $this->components->warn($note);
        }

        $this->warnIfGitHook($source, $tool);

        return self::SUCCESS;
    }

    /**
     * Where a tool keeps its worktree setup, what to add there, and what to
     * know about it.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     *
     * @throws JsonException
     */
    protected function recipe(string $source, string $tool, string $command): array
    {
        if ($tool === 'cursor') {
            return ['[.cursor/worktrees.json], which Cursor reads for every worktree it makes', SettingsFile::json(['setup-worktree' => [$command]]), [
                ...$this->mergeNote($source, '.cursor/worktrees.json'),
                'Put it after any command that copies .env, so adopt rewrites the copy.',
                'Cursor stops a setup after 300 seconds and starts its agent anyway. Turn on dependency copying to stay under that, and run php worktree-adopt.php in a worktree again to finish a setup it stopped.',
            ]];
        }

        if ($tool === 'codex') {
            return ["your project's local environment, as its setup script (the ChatGPT desktop app's settings, under Local environments)", $command, [
                'Choose that local environment when you start a chat in a worktree.',
            ]];
        }

        if ($tool === 'zed') {
            return ['[.zed/tasks.json], committed on the branch new worktrees start from', SettingsFile::json([[
                'label' => 'Set up the worktree',
                'command' => 'php',
                'args' => array_slice(explode(' ', $command), 1),
                'hooks' => ['create_worktree'],
                'reveal' => 'no_focus',
                'hide' => 'on_success',
            ]]), [
                ...$this->mergeNote($source, '.zed/tasks.json'),
                "Zed starts its agent without waiting for the task. worktree:install-agent git adopts Zed's worktrees inside git worktree add instead, in a project Zed trusts.",
            ]];
        }

        // Devin Desktop reads .windsurf/hooks.json only while .devin/hooks.json
        // is missing, so a project on the older file keeps it.
        $file = is_file($source.'/.windsurf/hooks.json') && ! is_file($source.'/.devin/hooks.json') ? '.windsurf/hooks.json' : '.devin/hooks.json';

        return ["[{$file}]", SettingsFile::json(['hooks' => ['post_setup_worktree' => [['command' => $command, 'show_output' => true]]]]), $this->mergeNote($source, $file)];
    }

    /**
     * @return list<string>
     */
    protected function mergeNote(string $source, string $file): array
    {
        return is_file($source.'/'.$file) ? ["Merge it into what [{$file}] already has."] : [];
    }

    /**
     * The command a tool's setup runs in each new worktree: the shim, which
     * adopts it from the main checkout, told which tool made it.
     */
    protected function shimCommand(string $tool): string
    {
        return implode(' ', ['php', 'worktree-adopt.php', '--tool='.$tool, ...$this->profile()]);
    }

    /**
     * The shim at the project root, published when it isn't there yet. A new
     * worktree holds only what is committed, so the shim has to be committed
     * on the branch the tool's worktrees start from.
     */
    protected function publishShim(string $source): bool
    {
        $shim = $source.'/worktree-adopt.php';

        if (! is_file($shim)) {
            if (! @copy(dirname(__DIR__, 2).'/resources/stubs/worktree-adopt.php', $shim)) {
                $this->components->error("Could not write [{$shim}].");

                return false;
            }

            $this->components->info('Published [worktree-adopt.php], which the tool runs in each new worktree.');
        }

        if (! $this->attempt(['git', 'ls-files', '--error-unmatch', 'worktree-adopt.php'], $source)) {
            $this->components->warn('Commit [worktree-adopt.php], and push it: a new worktree holds only what is committed on the branch it starts from, which some tools take from your remote.');
        }

        return true;
    }

    /**
     * Whether the repository's shared Conductor settings run a setup script,
     * which the local one takes the place of.
     */
    protected function hasConductorSetup(string $source): bool
    {
        if (preg_match('/^\s*setup\s*=/m', (string) @file_get_contents($source.'/.conductor/settings.toml')) === 1) {
            return true;
        }

        $json = json_decode((string) @file_get_contents($source.'/conductor.json'), true);

        return is_array($json) && Arr::get($json, 'scripts.setup', '') !== '';
    }

    /**
     * A tool whose worktree add runs git's hooks would have the git hook
     * adopt each of its worktrees too.
     */
    protected function warnIfGitHook(string $source, string $tool): void
    {
        if (in_array($tool, $this->hookless, true)) {
            return;
        }

        $hooks = trim($this->capture(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'hooks'], $source));

        if ($hooks !== '' && str_contains((string) @file_get_contents($hooks.'/post-checkout'), $this->gitHookMarker)) {
            $this->components->warn('The git hook worktree:install-agent git wrote adopts worktrees too, so this tool\'s would be set up twice. Keep one: remove the hook at ['.$hooks.'/post-checkout], or this setup.');
        }
    }

    /**
     * This repository's git directory and where git runs its hooks from,
     * refused when core.hooksPath moves them outside that directory: a global
     * path would carry the hook into every repository on the machine, and one
     * inside the working tree belongs to a hook manager or to a commit.
     *
     * @param  list<string>  $options
     * @return array{0: string, 1: string}|null
     */
    protected function hooksDirectory(string $source, string $artisan, array $options): ?array
    {
        $output = trim($this->capture(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir', '--git-path', 'hooks'], $source));
        $lines = preg_split('/\R/', $output) ?: [];

        if (count($lines) < 2) {
            $this->components->error("Could not tell where git keeps this repository's hooks; git 2.31 or later does.");

            return null;
        }

        [$common, $hooks] = $lines;

        if (! str_starts_with($this->canonical($hooks).'/', $this->canonical($common).'/')) {
            $this->components->error("core.hooksPath has git run hooks from [{$hooks}], outside this repository's git directory, so nothing is written there. To adopt each new worktree of this repository, add these lines at the end of the post-checkout hook there. They hold paths on this machine, so keep them out of a hooks directory you commit:");
            $this->printGitHookCommands($common, $source, $artisan, $options);

            return null;
        }

        return [$common, $hooks];
    }

    /**
     * The hook, for the sh git runs hooks with on every platform, Windows
     * included, with the LF line endings sh needs.
     *
     * @param  list<string>  $options
     */
    protected function gitHook(string $common, string $source, string $artisan, array $options): string
    {
        return implode("\n", [
            '#!/bin/sh',
            $this->gitHookMarker.' (mozex/laravel-worktree):',
            '# each worktree git worktree add makes gets its own databases, .env and site',
            '# through worktree:adopt. Running that command again replaces this whole file,',
            '# so put commands of your own above the checks below.',
            '',
            ...$this->gitHookCommands($common, $source, $artisan, $options),
            'exit 0',
            '',
        ]);
    }

    /**
     * What the hook runs, checks first: printed whole when it can't be
     * written, since the adopt line alone would run on every checkout.
     *
     * @param  string  $common  This repository's git directory, as git spells it.
     * @param  list<string>  $options
     * @return list<string>
     */
    protected function gitHookCommands(string $common, string $source, string $artisan, array $options): array
    {
        return [
            "# Only a new worktree's checkout of a branch starts from the null commit. The",
            '# main checkout and other repositories sharing these hooks are never adopted,',
            "# nor the worktrees worktree:setup and Claude Code's hook make for themselves.",
            'case "$1" in *[!0]*) exit 0 ;; esac',
            '[ "$3" = 1 ] || exit 0',
            '[ -n "$LARAVEL_WORKTREE_SKIP_ADOPT" ] && exit 0',
            '[ "$(git rev-parse --git-dir)" = "$(git rev-parse --git-common-dir)" ] && exit 0',
            '[ "$(git rev-parse --path-format=absolute --git-common-dir)" = '.$this->shellQuote($common).' ] || exit 0',
            '',
            'worktree=$(git rev-parse --show-toplevel) || exit 0',
            'unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE',
            '',
            '# A worktree switching back from an orphan branch starts from the null commit',
            '# too, and --unless-set-up leaves it as it is. git keeps the worktree whatever',
            '# this hook returns, so a failed adopt, which says what went wrong, never fails',
            '# the git worktree add that started it.',
            $this->adoptLine($source, $artisan, $options).' "$worktree" || :',
        ];
    }

    /**
     * @param  list<string>  $options
     */
    protected function printGitHookCommands(string $common, string $source, string $artisan, array $options): void
    {
        foreach ($this->gitHookCommands($common, $source, $artisan, $options) as $line) {
            $this->line($line);
        }
    }

    /**
     * Adopt run from the main checkout.
     *
     * @param  list<string>  $options
     */
    protected function adoptLine(string $source, string $artisan, array $options): string
    {
        return 'cd '.$this->shellQuote($source).' && '.$this->shellQuote(PHP_BINARY).' -d display_errors=stderr '.$this->shellQuote($artisan).' worktree:adopt '.implode(' ', $options);
    }

    /**
     * A path quoted for sh, with forward slashes, which PHP on Windows and
     * git's sh both take.
     */
    protected function shellQuote(string $path): string
    {
        return "'".str_replace("'", "'\\''", str_replace('\\', '/', $path))."'";
    }

    /**
     * A tool's local settings stay out of commits: they hold this machine's
     * paths, or one developer's own setup.
     */
    protected function warnIfShared(string $source, string $file, string $reason): void
    {
        if ($this->attempt(['git', 'ls-files', '--error-unmatch', $file], $source)) {
            $this->components->warn("[{$file}] is committed to this repository. {$reason}, so untrack it with git rm --cached, and add it to your .gitignore.");

            return;
        }

        if (! $this->attempt(['git', 'check-ignore', '-q', $file], $source)) {
            $this->components->warn("[{$file}] is not gitignored. {$reason}, so add it to your .gitignore.");
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
     * What the hook passes on to setup or adopt: http unless --secure, which
     * leaves HTTPS to site.secure, and the provisioning options given here.
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
