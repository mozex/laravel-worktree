<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

/**
 * A small string editor for .env files. It replaces a key's value in place,
 * appends the key when it is missing, and rewrites a bare hostname across the
 * whole file without disturbing longer hostnames that merely contain it.
 */
class EnvFile
{
    public function __construct(protected string $contents) {}

    public static function fromFile(string $path): self
    {
        return new self((string) file_get_contents($path));
    }

    public function get(string $key): ?string
    {
        if (preg_match($this->pattern($key), $this->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\"'");
    }

    public function set(string $key, string $value): self
    {
        $pattern = $this->pattern($key);
        $line = $key.'='.$this->escape($value);

        if (preg_match($pattern, $this->contents) === 1) {
            $this->contents = (string) preg_replace_callback(
                $pattern,
                fn (array $matches): string => $line.$matches[2],
                $this->contents,
            );

            return $this;
        }

        $this->contents = $this->append($line);

        return $this;
    }

    public function remapHost(string $from, string $to): self
    {
        $pattern = '/(?<![A-Za-z0-9.-])'.preg_quote($from, '/').'(?![A-Za-z0-9.-])/';

        $this->contents = (string) preg_replace($pattern, $this->quoteReplacement($to), $this->contents);

        return $this;
    }

    public function contents(): string
    {
        return $this->contents;
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->contents);
    }

    /**
     * Matches a key's line, capturing the value and any carriage return the
     * file uses, so a Windows line ending survives a rewrite instead of
     * leaking into the value.
     */
    protected function pattern(string $key): string
    {
        return '/^'.preg_quote($key, '/').'=([^\r\n]*)(\r?)$/m';
    }

    protected function newline(): string
    {
        return str_contains($this->contents, "\r\n") ? "\r\n" : "\n";
    }

    protected function append(string $line): string
    {
        $newline = $this->newline();
        $glue = $this->contents === '' || str_ends_with($this->contents, "\n") ? '' : $newline;

        return $this->contents.$glue.$line.$newline;
    }

    protected function escape(string $value): string
    {
        if (preg_match('/[\s#"\']/', $value) !== 1) {
            return $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }

    protected function quoteReplacement(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
