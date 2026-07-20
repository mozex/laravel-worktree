<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\FinishMode;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\Directory;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class TeardownCommand extends WorktreeCommand
{
    protected $signature = 'worktree:teardown
        {name? : The worktree name or branch to finish}
        {--pr : Push the branch and open a pull request}
        {--into= : Merge the branch into this target branch}
        {--abandon : Discard the branch without merging}
        {--message= : Commit message used when there are pending changes}
        {--keep-database : Do not drop the worktree databases}
        {--force : Skip confirmations and discard uncommitted changes}';

    protected $description = 'Finish a worktree (PR, merge, or abandon) and clean up its databases and files';

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

        $worktree = $this->selectWorktree($source);

        if ($worktree === null) {
            $this->components->info('No worktrees to finish.');

            return self::SUCCESS;
        }

        $mode = $this->resolveMode();

        $this->finish($worktree, $mode, $source);
        $this->cleanup($worktree, $mode, $source);

        $this->components->info("Finished worktree [{$worktree['branch']}].");

        return self::SUCCESS;
    }

    /**
     * @return array{path: string, branch: string|null}|null
     */
    protected function selectWorktree(string $source): ?array
    {
        $worktrees = array_values(array_filter(
            WorktreeList::parse($this->captureOrFail(['git', 'worktree', 'list', '--porcelain'], $source)),
            fn (array $entry): bool => ! $this->samePath($entry['path'], $source),
        ));

        if ($worktrees === []) {
            return null;
        }

        $name = (string) $this->argument('name');

        if ($name !== '') {
            return $this->matchWorktree($worktrees, $name);
        }

        if (count($worktrees) === 1) {
            return $worktrees[0];
        }

        $options = [];

        foreach ($worktrees as $entry) {
            $options[$entry['path']] = (string) $entry['branch'].' ('.basename($entry['path']).')';
        }

        $choice = (string) select(
            label: 'Which worktree do you want to finish?',
            options: $options,
        );

        return $this->matchWorktree($worktrees, $choice);
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $worktrees
     * @return array{path: string, branch: string|null}
     */
    protected function matchWorktree(array $worktrees, string $name): array
    {
        foreach ($worktrees as $entry) {
            if ($entry['branch'] === $name || $entry['path'] === $name || basename($entry['path']) === $name) {
                return $entry;
            }
        }

        throw WorktreeException::worktreeNotFound($name);
    }

    protected function resolveMode(): FinishMode
    {
        if ($this->option('abandon')) {
            return FinishMode::Abandon;
        }

        if ($this->option('into')) {
            return FinishMode::Merge;
        }

        if ($this->option('pr')) {
            return FinishMode::PullRequest;
        }

        return FinishMode::from(select(
            label: 'How do you want to finish this work?',
            options: [
                FinishMode::PullRequest->value => 'Push and open a pull request',
                FinishMode::Merge->value => 'Merge into another branch',
                FinishMode::Abandon->value => 'Abandon the changes',
            ],
            default: FinishMode::PullRequest->value,
        ));
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finish(array $worktree, FinishMode $mode, string $source): void
    {
        match ($mode) {
            FinishMode::PullRequest => $this->finishWithPullRequest($worktree),
            FinishMode::Merge => $this->finishWithMerge($worktree, $source),
            FinishMode::Abandon => $this->confirmAbandon($worktree),
        };
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finishWithPullRequest(array $worktree): void
    {
        $branch = $worktree['branch'];

        if ($branch === null) {
            throw WorktreeException::detachedWorktree($worktree['path']);
        }

        if ($this->isDirty($worktree['path'])) {
            $message = (string) ($this->option('message') ?: "Work on {$branch}");
            $this->process(['git', 'add', '-A'], $worktree['path']);
            $this->process(['git', 'commit', '-m', $message], $worktree['path']);
        }

        $this->process(['git', 'push', '-u', 'origin', $branch], $worktree['path']);
        $this->process(['gh', 'pr', 'create', '--base', $this->defaultBranch($worktree['path']), '--fill'], $worktree['path']);
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function finishWithMerge(array $worktree, string $source): void
    {
        if ($worktree['branch'] === null) {
            throw WorktreeException::detachedWorktree($worktree['path']);
        }

        if ($this->isDirty($worktree['path']) && ! $this->option('force')) {
            throw WorktreeException::dirtyWorktree($worktree['path']);
        }

        $target = $this->resolveMergeTarget($source);

        $this->process(['git', 'checkout', $target], $source);
        $this->process(['git', 'merge', '--no-ff', '--no-edit', $worktree['branch']], $source);
    }

    /**
     * The merge mode is reachable from the interactive prompt as well as from
     * --into, so the target has to be asked for when the option is absent.
     */
    protected function resolveMergeTarget(string $source): string
    {
        $target = (string) $this->option('into');

        if ($target !== '') {
            return $target;
        }

        return text(
            label: 'Which branch should this merge into?',
            default: $this->defaultBranch($source),
            required: true,
        );
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function confirmAbandon(array $worktree): void
    {
        if ($this->option('force')) {
            return;
        }

        if (confirm(label: "Discard all changes on [{$worktree['branch']}]?", default: false)) {
            return;
        }

        // Also the --no-interaction path, where the confirm returns its default
        // and nobody actually said no, so the message has to fit both.
        throw WorktreeException::abandonNotConfirmed((string) $worktree['branch']);
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function cleanup(array $worktree, FinishMode $mode, string $source): void
    {
        // Each test suite connection lives in the worktree's PHPUnit file, which
        // is about to be deleted with it, so they are resolved up front.
        $testConnections = $this->testConnections($worktree['path']);

        $this->unserveWithHerd($worktree);

        $force = $this->option('force') || $mode === FinishMode::Abandon;
        $remove = ['git', 'worktree', 'remove', $worktree['path']];

        if ($force) {
            $remove[] = '--force';
        }

        // Remove the worktree before dropping anything: if the removal fails,
        // the databases are still around to retry against.
        $this->process($remove, $source);

        // git leaves behind whatever it will not follow, such as the public/storage
        // link a storage:link step creates. The directory would then survive and
        // block worktree:setup for this branch, so clear it out.
        if (is_dir($worktree['path']) && ! Directory::delete($worktree['path'])) {
            $this->components->warn("Could not fully remove [{$worktree['path']}]; delete it by hand.");
        }

        $this->dropDatabases($worktree, $source, $testConnections);
        $this->deleteBranch($worktree, $mode, $source);
        $this->attempt(['git', 'worktree', 'prune'], $source);
    }

    /**
     * The connection each configured entry's test database ran on, mirroring
     * what setup provisioned. Keyed by the entry's index so a drop can find its
     * server after the worktree (and its PHPUnit file) is gone.
     *
     * @return array<int, string|null>
     */
    protected function testConnections(string $path): array
    {
        $connections = [];

        foreach ($this->databaseConnections() as $index => $entry) {
            $connections[$index] = $this->testConnectionFor($entry, $path);
        }

        return $connections;
    }

    /**
     * Every worktree database, application and test, on the connection setup
     * created it on. A file database lived inside the worktree and went with it,
     * so only server connections have anything to drop.
     *
     * @param  array{path: string, branch: string|null}  $worktree
     * @param  array<int, string|null>  $testConnections
     */
    protected function dropDatabases(array $worktree, string $source, array $testConnections): void
    {
        if ($this->option('keep-database') || ! (bool) Arr::get($this->settings(), 'database.enabled', true)) {
            return;
        }

        if ($worktree['branch'] === null) {
            return;
        }

        $names = Worktree::make($source, $worktree['branch'], $this->settings());

        /** @var array<string, array{manager: DatabaseManager, env: string}> $drops */
        $drops = [];

        foreach ($this->databaseConnections() as $index => $entry) {
            $app = $this->databases($entry['connection']);

            if ($app->isServer()) {
                $drops[$names->database($entry['name'])] = ['manager' => $app, 'env' => $entry['env']];
            }

            if ($entry['test'] === null) {
                continue;
            }

            $test = $this->databases($testConnections[$index] ?? null);

            if ($test->isServer()) {
                $drops[$names->database($entry['test']['name'])] = ['manager' => $test, 'env' => $entry['test']['env']];
            }
        }

        if ($drops === []) {
            return;
        }

        foreach ($drops as $database => $meta) {
            if (! $this->isSourceDatabase($source, $meta['env'], $database)) {
                continue;
            }

            $this->components->warn("Refusing to drop [{$database}]; it matches the main repository database.");

            return;
        }

        $list = implode('] and [', array_keys($drops));

        if (! $this->option('force') && ! confirm(label: "Drop databases [{$list}]?", default: true)) {
            return;
        }

        foreach ($drops as $database => $meta) {
            $meta['manager']->drop($database);
        }
    }

    /**
     * Reads the same env file setup copied from, so pointing env.source at a
     * non-default file cannot quietly disable the guard. Each connection is
     * checked against its own env key, so every connection's main database is
     * protected, not just the default one.
     */
    protected function isSourceDatabase(string $source, string $envKey, string $database): bool
    {
        $env = $source.'/'.(string) Arr::get($this->settings(), 'env.source', '.env');

        if (! File::exists($env)) {
            return false;
        }

        return EnvFile::fromFile($env)->get($envKey) === $database;
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function unserveWithHerd(array $worktree): void
    {
        $herd = HerdMode::tryFrom((string) Arr::get($this->settings(), 'herd', HerdMode::Secure->value)) ?? HerdMode::Secure;

        if (! $herd->enabled() || ! is_dir($worktree['path'])) {
            return;
        }

        // Setup links the site in every mode (see SetupCommand::serveWithHerd),
        // so the link is removed in every mode too.
        if ($herd === HerdMode::Secure) {
            $this->attempt(['herd', 'unsecure'], $worktree['path']);
        }

        $this->attempt(['herd', 'unlink', basename($worktree['path'])], $worktree['path']);
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function deleteBranch(array $worktree, FinishMode $mode, string $source): void
    {
        if ($mode === FinishMode::PullRequest || $worktree['branch'] === null) {
            return;
        }

        $flag = $mode === FinishMode::Merge ? '-d' : '-D';

        $this->attempt(['git', 'branch', $flag, $worktree['branch']], $source);
    }

    protected function isDirty(string $path): bool
    {
        return trim($this->captureOrFail(['git', 'status', '--porcelain'], $path)) !== '';
    }

    protected function defaultBranch(string $path): string
    {
        $reference = trim($this->capture(['git', 'symbolic-ref', 'refs/remotes/origin/HEAD'], $path));

        if ($reference !== '') {
            return (string) preg_replace('#^refs/remotes/origin/#', '', $reference);
        }

        return (string) Arr::get($this->settings(), 'base_branch', 'main');
    }
}
