<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Mozex\Worktree\Enums\FinishMode;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\DatabaseManager;
use Mozex\Worktree\Support\EnvFile;
use Mozex\Worktree\Support\WorktreeList;
use Mozex\Worktree\Worktree;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

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

    public function __construct()
    {
        parent::__construct();

        $this->description = 'Finish a worktree (PR, merge, or abandon) and clean up its databases and files';
    }

    public function handle(): int
    {
        $source = base_path();

        if (! $this->isGitRepository($source)) {
            $this->components->error("[{$source}] is not a git repository.");

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
        $normalized = str_replace('\\', '/', rtrim($source, '/\\'));

        $worktrees = array_values(array_filter(
            WorktreeList::parse($this->capture(['git', 'worktree', 'list', '--porcelain'], $source)),
            fn (array $entry): bool => $entry['path'] !== $normalized,
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

        $choice = select(
            label: 'Which worktree do you want to finish?',
            options: array_map(fn (array $entry): string => (string) $entry['branch'].' ('.basename($entry['path']).')', $worktrees),
        );

        return $worktrees[$this->indexOfChoice($worktrees, $choice)];
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $worktrees
     * @return array{path: string, branch: string|null}
     */
    protected function matchWorktree(array $worktrees, string $name): array
    {
        foreach ($worktrees as $entry) {
            if ($entry['branch'] === $name || basename($entry['path']) === $name) {
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
        $branch = (string) $worktree['branch'];

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
        if ($this->isDirty($worktree['path']) && ! $this->option('force')) {
            throw WorktreeException::dirtyWorktree($worktree['path']);
        }

        $target = (string) $this->option('into');

        $this->process(['git', 'checkout', $target], $source);
        $this->process(['git', 'merge', '--no-ff', '--no-edit', (string) $worktree['branch']], $source);
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

        throw WorktreeException::commandFailed('worktree:teardown', 'Aborted by user.');
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function cleanup(array $worktree, FinishMode $mode, string $source): void
    {
        $this->dropDatabases($worktree, $source);
        $this->unserveWithHerd($worktree);

        $force = $this->option('force') || $mode === FinishMode::Abandon;
        $remove = ['git', 'worktree', 'remove', $worktree['path']];

        if ($force) {
            $remove[] = '--force';
        }

        $this->process($remove, $source);
        $this->deleteBranch($worktree, $mode, $source);
        $this->attempt(['git', 'worktree', 'prune'], $source);
    }

    /**
     * @param  array{path: string, branch: string|null}  $worktree
     */
    protected function dropDatabases(array $worktree, string $source): void
    {
        if ($this->option('keep-database') || ! (bool) Arr::get($this->settings(), 'database.enabled', true)) {
            return;
        }

        if ($worktree['branch'] === null) {
            return;
        }

        $names = Worktree::make($source, $worktree['branch'], $this->settings());
        $appDatabase = $names->appDatabase();
        $testDatabase = $names->testDatabase();

        if ($this->isSourceDatabase($source, $appDatabase)) {
            $this->components->warn("Refusing to drop [{$appDatabase}]; it matches the main repository database.");

            return;
        }

        if (! $this->option('force') && ! confirm(label: "Drop databases [{$appDatabase}] and [{$testDatabase}]?", default: true)) {
            return;
        }

        $databases = new DatabaseManager($this->connectionConfig());

        if (! $databases->supported()) {
            return;
        }

        $databases->drop($appDatabase);
        $databases->drop($testDatabase);
    }

    protected function isSourceDatabase(string $source, string $database): bool
    {
        $env = $source.'/.env';

        if (! File::exists($env)) {
            return false;
        }

        return EnvFile::fromFile($env)->get('DB_DATABASE') === $database;
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

        $command = $herd === HerdMode::Secure
            ? ['herd', 'unsecure']
            : ['herd', 'unlink', basename($worktree['path'])];

        $this->attempt($command, $worktree['path']);
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
        return trim($this->capture(['git', 'status', '--porcelain'], $path)) !== '';
    }

    protected function defaultBranch(string $path): string
    {
        $reference = trim($this->capture(['git', 'symbolic-ref', 'refs/remotes/origin/HEAD'], $path));

        if ($reference !== '') {
            return (string) preg_replace('#^refs/remotes/origin/#', '', $reference);
        }

        return (string) Arr::get($this->settings(), 'base_branch', 'main');
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $worktrees
     */
    protected function indexOfChoice(array $worktrees, string $choice): int
    {
        foreach ($worktrees as $index => $entry) {
            if ((string) $entry['branch'].' ('.basename($entry['path']).')' === $choice) {
                return $index;
            }
        }

        return 0;
    }
}
