<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use JsonException;
use stdClass;

/**
 * A Claude Code settings file, read and written as JSON objects rather than
 * PHP arrays: an array decode turns an empty object ("env": {}) into an empty
 * list, which saving would then write over the user's own settings. Pure but
 * for reading and saving the file.
 */
class ClaudeSettings
{
    /**
     * The hook events whose handlers this package installs.
     *
     * @var list<string>
     */
    protected static array $events = ['WorktreeCreate', 'WorktreeRemove'];

    public function __construct(protected stdClass $settings = new stdClass) {}

    /**
     * A missing file holds no settings. Null when the file is not a JSON
     * object, or its hooks are not shaped the way Claude Code reads them, so
     * nothing would be written over it.
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
            $settings = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $settings instanceof stdClass) {
            return null;
        }

        $hooks = $settings->hooks ?? new stdClass;

        if (! $hooks instanceof stdClass) {
            return null;
        }

        foreach (self::$events as $event) {
            if (isset($hooks->{$event}) && ! is_array($hooks->{$event})) {
                return null;
            }
        }

        return new self($settings);
    }

    /**
     * Every handler the event runs, from every matcher group.
     *
     * @return list<mixed>
     */
    public function handlers(string $event): array
    {
        $handlers = [];

        foreach ($this->groups($event) as $group) {
            $hooks = $group instanceof stdClass && is_array($group->hooks ?? null) ? $group->hooks : [];

            array_push($handlers, ...array_values($hooks));
        }

        return $handlers;
    }

    /**
     * Whether a handler runs worktree:hook, in exec form or as a shell
     * command, so a rerun replaces it rather than adding a second one.
     */
    public static function isOurs(mixed $handler): bool
    {
        if (! $handler instanceof stdClass) {
            return false;
        }

        $args = $handler->args ?? null;

        if (is_array($args) && in_array('worktree:hook', $args, true)) {
            return true;
        }

        return is_string($handler->command ?? null) && str_contains($handler->command, 'worktree:hook');
    }

    /**
     * The events holding a handler that isn't this package's.
     *
     * @return list<string>
     */
    public function foreignEvents(): array
    {
        return array_values(array_filter(
            self::$events,
            fn (string $event): bool => array_filter($this->handlers($event), fn (mixed $handler): bool => ! self::isOurs($handler)) !== [],
        ));
    }

    /**
     * The events holding any handler at all.
     *
     * @return list<string>
     */
    public function hookedEvents(): array
    {
        return array_values(array_filter(self::$events, fn (string $event): bool => $this->handlers($event) !== []));
    }

    /**
     * Puts one handler per event in place of the ones this package wrote
     * before, leaving everything else as it was.
     *
     * @param  array<string, array<string, mixed>>  $handlers  Keyed by event.
     */
    public function install(array $handlers): void
    {
        if (! ($this->settings->hooks ?? null) instanceof stdClass) {
            $this->settings->hooks = new stdClass;
        }

        foreach ($handlers as $event => $handler) {
            $groups = [];

            foreach ($this->groups($event) as $group) {
                if ($group instanceof stdClass && is_array($group->hooks ?? null)) {
                    $kept = array_values(array_filter($group->hooks, fn (mixed $hook): bool => ! self::isOurs($hook)));

                    if ($kept === [] && $group->hooks !== []) {
                        continue;
                    }

                    $group->hooks = $kept;
                }

                $groups[] = $group;
            }

            $groups[] = (object) ['hooks' => [(object) $handler]];

            $this->settings->hooks->{$event} = $groups;
        }
    }

    /**
     * Indented by two spaces, as Claude Code writes its own settings.
     *
     * @throws JsonException
     */
    public function toJson(): string
    {
        return SettingsFile::json($this->settings);
    }

    /**
     * Written to a temporary file and renamed into place, so Claude Code's
     * file watcher never reads half a file.
     */
    public function save(string $file): bool
    {
        try {
            return SettingsFile::write($file, $this->toJson());
        } catch (JsonException) {
            return false;
        }
    }

    /**
     * @return list<mixed>
     */
    protected function groups(string $event): array
    {
        $hooks = $this->settings->hooks ?? null;
        $groups = $hooks instanceof stdClass ? ($hooks->{$event} ?? []) : [];

        return is_array($groups) ? array_values($groups) : [];
    }
}
