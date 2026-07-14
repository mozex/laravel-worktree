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
        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $this->contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\"'");
    }

    public function set(string $key, string $value): self
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $line = $key.'='.$this->escape($value);

        if (preg_match($pattern, $this->contents) === 1) {
            $this->contents = (string) preg_replace($pattern, $this->quoteReplacement($line), $this->contents);

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

    protected function append(string $line): string
    {
        $glue = $this->contents === '' || str_ends_with($this->contents, "\n") ? '' : "\n";

        return $this->contents.$glue.$line."\n";
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
