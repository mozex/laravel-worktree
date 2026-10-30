<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use JsonException;
use stdClass;

/**
 * Paradev's (formerly Superset's) local config, .superset/config.local.json,
 * read and written as JSON objects so nothing else in it changes shape. Its
 * setup is either a list that replaces the repository's setup commands or an
 * object whose before and after lists run around them. Pure but for reading
 * and saving the file.
 */
class ParadevConfig
{
    public function __construct(protected stdClass $config = new stdClass) {}

    /**
     * A missing file holds no config. Null when the file is not a JSON object,
     * or its setup is not shaped the way Paradev reads it, so nothing would be
     * written over it.
     */
    public static function fromFile(string $file): ?self
    {
        if (! is_file($file)) {
            return new self;
        }

        $contents = @file_get_contents($file);

        return $contents === false ? null : self::parse($contents);
    }

    public static function parse(string $json): ?self
    {
        try {
            $config = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $config instanceof stdClass) {
            return null;
        }

        $setup = $config->setup ?? [];

        if (! $setup instanceof stdClass) {
            return self::isCommandList($setup) ? new self($config) : null;
        }

        foreach (['before', 'after'] as $key) {
            if (isset($setup->{$key}) && ! self::isCommandList($setup->{$key})) {
                return null;
            }
        }

        return new self($config);
    }

    /**
     * Runs the command after every other setup command, in place of one this
     * package wrote before: the repository's own setup may copy .env, which
     * the adopt that follows then rewrites for the worktree.
     */
    public function install(string $command): void
    {
        $setup = $this->config->setup ?? new stdClass;

        if (is_array($setup)) {
            $this->config->setup = [...self::withoutOurs($setup), $command];

            return;
        }

        $setup->after = [...self::withoutOurs(is_array($setup->after ?? null) ? $setup->after : []), $command];
        $this->config->setup = $setup;
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return SettingsFile::json($this->config);
    }

    public function save(string $file): bool
    {
        try {
            return SettingsFile::write($file, $this->toJson());
        } catch (JsonException) {
            return false;
        }
    }

    /**
     * Whether a command runs the adopt shim, so a rerun replaces it rather
     * than adding a second one.
     */
    public static function isOurs(mixed $command): bool
    {
        return is_string($command) && str_contains($command, 'worktree-adopt.php');
    }

    protected static function isCommandList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && array_filter($value, fn (mixed $command): bool => ! is_string($command)) === [];
    }

    /**
     * @param  array<int, mixed>  $commands
     * @return list<mixed>
     */
    protected static function withoutOurs(array $commands): array
    {
        return array_values(array_filter($commands, fn (mixed $command): bool => ! self::isOurs($command)));
    }
}
