<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

/**
 * Reads a server's list of sites, as its CLI prints it, for one site name.
 * Pure. Every check compares without case, since hosts ignore it, and none
 * takes a name that merely starts another one: "blog-feature" is not listed
 * when only "blog-feature-login" is.
 */
class SiteList
{
    /**
     * Whether a table lists the name as a cell of its own. Cells are split on
     * the column rules Symfony and Go tables print ("|", "│", a tab, or a run
     * of spaces), so a path or URL that holds the name does not count. The
     * box rule is matched by its bytes, so a line that is not valid UTF-8 (a
     * Windows path in the system code page) is still read.
     */
    public static function hasCell(string $table, string $name): bool
    {
        foreach (static::cells($table) as $cell) {
            if (strcasecmp($cell, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a table lists the name, or a cell cut short with "..." whose
     * remaining text begins it, as a CLI does to fit a long name into its
     * column. The kept text must be long enough to tell names apart.
     */
    public static function hasShortenedCell(string $table, string $name, int $minimum = 16): bool
    {
        if (static::hasCell($table, $name)) {
            return true;
        }

        foreach (static::cells($table) as $cell) {
            if (! str_ends_with($cell, '...')) {
                continue;
            }

            $kept = substr($cell, 0, -3);

            if (strlen($kept) >= $minimum && stripos($name, $kept) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any output names the site, alone or inside a host, a URL, or a
     * path. Nothing that could belong to a name may come before it, and after
     * it comes either the end of the name or ".{tld}", so neither
     * "blog-feature-login" nor "blog-feature.2.test" counts as "blog-feature".
     */
    public static function mentions(string $output, string $name, string $tld): bool
    {
        $boundary = '(?![A-Za-z0-9_.-])';

        return (bool) preg_match(
            '/(?<![A-Za-z0-9._-])'.preg_quote($name, '/').'(?:'.$boundary.'|\.'.preg_quote($tld, '/').$boundary.')/i',
            $output,
        );
    }

    /**
     * @return list<string>
     */
    protected static function cells(string $table): array
    {
        $cells = [];

        foreach (preg_split('/\R/', $table) ?: [] as $line) {
            foreach (preg_split('/\xE2\x94\x82|[|\t]| {2,}/', $line) ?: [] as $cell) {
                $cells[] = trim($cell);
            }
        }

        return $cells;
    }
}
