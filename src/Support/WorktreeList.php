<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

/**
 * Parses the output of "git worktree list --porcelain" into plain entries.
 */
class WorktreeList
{
    /**
     * @return array<int, array{path: string, branch: string|null}>
     */
    public static function parse(string $porcelain): array
    {
        $entries = [];
        $current = ['path' => null, 'branch' => null];

        foreach (preg_split('/\R/', trim($porcelain)) ?: [] as $line) {
            if ($line === '') {
                $entries = self::flush($entries, $current);
                $current = ['path' => null, 'branch' => null];

                continue;
            }

            if (str_starts_with($line, 'worktree ')) {
                $current['path'] = str_replace('\\', '/', mb_substr($line, 9));
            }

            if (str_starts_with($line, 'branch ')) {
                $current['branch'] = (string) preg_replace('#^refs/heads/#', '', mb_substr($line, 7));
            }
        }

        return self::flush($entries, $current);
    }

    /**
     * @param  array<int, array{path: string, branch: string|null}>  $entries
     * @param  array{path: string|null, branch: string|null}  $current
     * @return array<int, array{path: string, branch: string|null}>
     */
    protected static function flush(array $entries, array $current): array
    {
        if ($current['path'] === null) {
            return $entries;
        }

        $entries[] = ['path' => $current['path'], 'branch' => $current['branch']];

        return $entries;
    }
}
