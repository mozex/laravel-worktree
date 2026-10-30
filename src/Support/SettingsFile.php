<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use JsonException;

/**
 * Writes a tool's settings file the way the tool writes its own. Pure but for
 * saving the file.
 */
class SettingsFile
{
    /**
     * Indented by two spaces, as Claude Code and the agent tools write theirs.
     *
     * @throws JsonException
     */
    public static function json(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (string) preg_replace_callback('/^(?: {4})+/m', fn (array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 2)), $json)."\n";
    }

    /**
     * Written to a temporary file and renamed into place, so a tool watching
     * the file never reads half of it.
     */
    public static function write(string $file, string $contents): bool
    {
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            return false;
        }

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents) === false || ! Directory::replaceFile($temporary, $file)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
