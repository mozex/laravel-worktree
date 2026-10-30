<?php

declare(strict_types=1);

namespace Mozex\Worktree\Commands;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Arr;
use Mozex\Worktree\Worktree;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * Claude Code's WorktreeCreate and WorktreeRemove hooks. A WorktreeCreate hook
 * replaces git worktree add for every worktree Claude makes (--worktree,
 * EnterWorktree, a subagent with isolation: worktree, a background session),
 * so create makes the worktree the way setup does and prints its path, the
 * one line Claude reads from stdout; everything else goes to stderr. Remove
 * finishes a worktree create made and keeps its branch: Claude knows only the
 * path, and never deletes the branch of a worktree a hook made.
 */
class HookCommand extends ProvisionCommand
{
    protected $signature = 'worktree:hook
        {tool : The tool running the hook: claude}
        {event : The hook event: create or remove}
        {--base= : Base branch used when creating a new branch}
        {--no-database : Skip creating databases and patching PHPUnit}
        {--no-migrate : Skip migrating the application database}
        {--no-install : Skip installing or copying dependencies, plus the migrations and steps that need them}
        {--seed : Seed the application database after migrating}
        {--clone : Copy the data of the main repository into the worktree databases}
        {--no-clone : Start from empty databases even when cloning is configured}';

    protected $description = "Run Claude Code's WorktreeCreate or WorktreeRemove hook, reading its JSON from stdin";

    public function handle(): int
    {
        // Claude reads the worktree's path from stdout, so nothing else may go there.
        $this->routeHumanToError = true;

        // Laravel prints a command's exception on stdout, which Claude reads
        // only for the path; its debug log and agent view show stderr.
        try {
            return $this->runHook();
        } catch (Throwable $exception) {
            $this->laravel->make(ExceptionHandler::class)->report($exception);
            $this->display()->error($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class);

            return self::FAILURE;
        }
    }

    protected function runHook(): int
    {
        $source = $this->laravel->basePath();

        // The hook starts wherever Claude is working, often inside the
        // worktree, and Windows will not delete a directory a process is in.
        @chdir($source);

        if (! $this->isGitRepository($source)) {
            $this->display()->error("[{$source}] is not a git repository.");

            return self::FAILURE;
        }

        if (! $this->isMainRepository($source)) {
            $this->display()->error("[{$source}] is a linked worktree. Run this from the main repository at [{$this->mainWorktreePath($source)}].");

            return self::FAILURE;
        }

        $tool = (string) $this->argument('tool');

        if ($tool !== 'claude') {
            $this->display()->error("worktree:hook runs Claude Code's hooks, so the tool is claude, not [{$tool}].");

            return self::FAILURE;
        }

        $event = (string) $this->argument('event');

        if (! in_array($event, ['create', 'remove'], true)) {
            $this->display()->error("The hook event is create or remove, not [{$event}].");

            return self::FAILURE;
        }

        $payload = $this->payload();

        if ($payload === null) {
            $this->display()->error("The hook's input is not a JSON object. Claude Code passes it on stdin.");

            return self::FAILURE;
        }

        return $event === 'create' ? $this->create($source, $payload) : $this->remove($source, $payload);
    }

    /**
     * The JSON Claude Code writes to the hook's stdin. Run by hand from a
     * terminal there is none, and waiting for it would hang.
     *
     * @return array<array-key, mixed>|null
     */
    protected function payload(): ?array
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;

        if (stream_isatty($stream)) {
            return null;
        }

        $payload = json_decode((string) stream_get_contents($stream), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    protected function create(string $source, array $payload): int
    {
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : '';

        // Kept to what a branch, a host and a directory take.
        $label = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.');

        if ($label === '') {
            $this->display()->error('The hook\'s input names no worktree: Claude Code passes its name as "name".');

            return self::FAILURE;
        }

        // How Claude names the branches of the worktrees it makes itself, and
        // the prefix keeps a worktree named like an existing branch, such as
        // main, off that branch.
        $branch = 'worktree-'.$label;
        $worktree = $this->worktreeFor($source, $branch);

        if ($this->isRegistered($worktree->path(), $source)) {
            $handedBack = $this->handBack($source, $worktree);

            if ($handedBack !== null) {
                return $handedBack;
            }
        }

        $this->tool = 'claude';
        $this->recordExtras = is_string($payload['agent_id'] ?? null) && $payload['agent_id'] !== '' ? ['agent' => $payload['agent_id']] : [];

        $branchExisted = $this->attempt(['git', 'show-ref', '--verify', '--quiet', "refs/heads/{$branch}"], $source);
        $base = (string) ($this->option('base') ?: Arr::get($this->settings(), 'base_branch', 'main'));
        $created = false;

        try {
            $this->provision($worktree, $branch, function () use ($worktree, $base, &$created): bool {
                $this->display()->info("Creating worktree [{$worktree->name()}] on branch [{$worktree->branch()}]");

                $resumed = $this->createWorktree($worktree, $base);
                $created = ! $resumed;

                return $resumed;
            });
        } catch (Throwable $exception) {
            if ($created) {
                $this->rollBack($worktree, $branchExisted);
            }

            throw $exception;
        }

        $this->line($this->printablePath($worktree));

        return self::SUCCESS;
    }

    /**
     * Claude asks again for a worktree it comes back to, maybe on another
     * branch by then, and setting it up again would migrate its databases
     * fresh, or clone them over, so one whose provisioning finished is handed
     * back as it is. One whose last run stopped partway (killed at the hook's
     * time limit, say) is provisioned again: null says so. Without a record,
     * nothing says what the worktree holds, so it is left alone.
     */
    protected function handBack(string $source, Worktree $worktree): ?int
    {
        $path = $worktree->path();

        if (! is_dir($path)) {
            $this->display()->error("git still lists the worktree at [{$path}], but its directory is gone. worktree:prune shows what it left behind and --force cleans that up; git worktree prune makes git forget it.");

            return self::FAILURE;
        }

        $registry = $this->registry($source);
        $record = $registry?->find($this->canonical($path));

        if ($record === null) {
            $this->display()->error($registry === null
                ? "[{$path}] is already a worktree, and without a readable registry nothing says whether it was set up, so it is left as it is."
                : "[{$path}] is already a worktree this package has no record of, so it is left as it is. worktree:setup or worktree:adopt sets it up, and worktree:teardown finishes it.");

            return self::FAILURE;
        }

        if ($record['provisioned'] === null) {
            return null;
        }

        $this->display()->info("Worktree [{$worktree->name()}] is already set up.");
        $this->line($this->printablePath($worktree));

        return self::SUCCESS;
    }

    /**
     * Claude refuses a path with "." or ".." in it or one passing through a
     * link, so it gets the directory as the filesystem names it.
     */
    protected function printablePath(Worktree $worktree): string
    {
        return realpath($worktree->path()) ?: $worktree->path();
    }

    /**
     * Claude never learns the path of a worktree whose create failed, so
     * nothing would ever finish it: what this run made goes again, through
     * teardown, which reads what was recorded as soon as git made it. A branch
     * that was there before this run stays.
     */
    protected function rollBack(Worktree $worktree, bool $keepBranch): void
    {
        $this->display()->warn("Setting up [{$worktree->name()}] failed, so what this run made is removed again.");

        try {
            $this->abandon($worktree->path(), $keepBranch);
        } catch (Throwable $exception) {
            $this->display()->warn("Could not remove [{$worktree->path()}] again ({$exception->getMessage()}); finish it with worktree:teardown.");
        }
    }

    /**
     * Claude counts the worktree as removed once this exits 0, and as kept
     * when it exits otherwise with the directory still there.
     *
     * @param  array<array-key, mixed>  $payload
     */
    protected function remove(string $source, array $payload): int
    {
        $path = is_string($payload['worktree_path'] ?? null) ? trim($payload['worktree_path']) : '';

        if ($path === '') {
            $this->display()->error('The hook\'s input names no worktree: Claude Code passes its path as "worktree_path".');

            return self::FAILURE;
        }

        if ($this->samePath($path, $source)) {
            $this->display()->error("[{$path}] is the main repository, which worktree:hook never removes.");

            return self::FAILURE;
        }

        $listed = $this->isRegistered($path, $source);

        if (! $listed && ! is_dir($path)) {
            $this->display()->info("[{$path}] is already gone.");

            return self::SUCCESS;
        }

        $record = $this->registry($source)?->find($this->canonical($path));

        // Claude removes only worktrees its hook made, so one setup or another
        // tool made, or one with no record to say, is not this hook's to delete.
        if (! $listed || $record === null || $record['tool'] !== 'claude') {
            $this->display()->error("[{$path}] was not set up by worktree:hook, so it is left alone. Finish it with worktree:teardown.");

            return self::FAILURE;
        }

        // Claude removes an unnamed session's worktree without asking when git
        // shows no changes, and ExitWorktree with discard_changes leaves this
        // hook as the last check, so unsaved work is never thrown away here.
        if (is_dir($path) && $this->hasUnsavedWork($path)) {
            $this->display()->error("[{$path}] has uncommitted or untracked changes, or commits no branch holds, so it is kept. Commit them on a branch, or finish it with worktree:teardown.");

            return self::FAILURE;
        }

        // The branch keeps whatever was committed on it.
        $this->abandon($path, keepBranch: true);

        clearstatcache(true, $path);

        if (is_dir($path)) {
            $this->display()->error("Could not remove [{$path}]; delete it by hand.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Finishes the worktree through worktree:teardown, discarding it unmerged
     * without asking.
     */
    protected function abandon(string $path, bool $keepBranch): void
    {
        $this->runCommand('worktree:teardown', array_filter([
            'name' => $path,
            '--abandon' => true,
            '--force' => true,
            '--keep-branch' => $keepBranch,
            '--no-interaction' => true,
        ]), $this->humanOutput());
    }

    /**
     * Work removing the worktree would lose: changes git sees, untracked files
     * included whatever status.showUntrackedFiles says, and commits made on a
     * detached HEAD that no branch or tag holds, which go with the worktree's
     * own reflog.
     */
    protected function hasUnsavedWork(string $path): bool
    {
        if (trim($this->captureOrFail(['git', 'status', '--porcelain', '--untracked-files=all', '--ignore-submodules=none'], $path)) !== '') {
            return true;
        }

        // Refs under refs/worktree and the like belong to the worktree and go
        // with it, so only shared ones count.
        return trim($this->captureOrFail(['git', 'rev-parse', '--symbolic-full-name', 'HEAD'], $path)) === 'HEAD'
            && trim($this->captureOrFail(['git', 'for-each-ref', '--contains', 'HEAD', '--count=1', 'refs/heads', 'refs/tags', 'refs/remotes'], $path)) === '';
    }
}
