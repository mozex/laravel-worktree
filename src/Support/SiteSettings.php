<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use Closure;
use Illuminate\Support\Arr;
use Mozex\Worktree\Contracts\SiteDriver;
use Mozex\Worktree\Enums\HerdMode;
use Mozex\Worktree\Exceptions\WorktreeException;
use ReflectionClass;

/**
 * Which site driver serves the worktrees, and how, resolved from the package
 * configuration. Pure: whether a driver's tools are installed is asked through
 * a callback.
 *
 * The settings are read here rather than trusted to the config merge, for two
 * reasons. A published config's new keys are always filled from the defaults,
 * so "the user chose a driver" cannot be told apart from "the default filled
 * it in", which is why site.driver ships empty and the legacy "herd" key
 * decides whenever it is. And a cached config skips the merge entirely, so a
 * config cached under an older release has no "site" block at all; every key
 * read here has a default of its own.
 */
class SiteSettings
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        protected string $driver,
        protected bool $secure,
        protected int $timeout,
        protected array $warnings = [],
    ) {}

    /**
     * site.driver wins when set: a driver name or a class implementing the
     * driver contract, and anything else throws. Otherwise the legacy "herd"
     * value decides (secure, link, or none), and an unknown one keeps meaning
     * Herd over HTTPS, as it always did, with a warning. With neither set,
     * "auto" picks Herd, then Valet, whichever is installed first. A driver
     * asked for by name but not installed serves nothing, with a warning;
     * "auto" falls back quietly.
     *
     * @param  array<string, mixed>  $settings  The worktree configuration.
     * @param  Closure(string): bool  $available  Whether a driver's tools are installed.
     */
    public static function resolve(array $settings, Closure $available): self
    {
        $chosen = Arr::get($settings, 'site.driver');
        $legacy = Arr::get($settings, 'herd');
        $mode = is_string($legacy) ? HerdMode::tryFrom($legacy) : null;
        $requested = static::requested($chosen, $legacy, $mode);

        // Chosen by name with nothing to run is a mistake in the config, like an
        // unknown driver, not a server that happens to be missing.
        if ($requested === 'command' && in_array(Arr::get($settings, 'site.drivers.command.serve'), [null, '', []], true)) {
            throw WorktreeException::commandDriverUnconfigured();
        }

        $driver = static::installed($requested, $available);
        $warnings = [];

        if (! static::isUnset($legacy) && $mode === null && static::isUnset($chosen)) {
            $warnings[] = 'The worktree.herd value ['.static::describe($legacy).'] is not one of secure, link, or none, so it is read as secure, as before. Choose the server with worktree.site.driver (WORKTREE_DRIVER) instead.';
        }

        if ($requested !== 'auto' && $driver !== $requested) {
            $warnings[] = "The [{$requested}] site driver is configured, but ".static::unavailable($requested).', so the worktree is not served and APP_URL uses http. '.static::remedy($requested).' See "Site Drivers" in the README.';
        }

        $secure = Arr::get($settings, 'site.secure');

        if (! static::isUnset($secure) && static::toBool($secure) === null) {
            $warnings[] = 'The worktree.site.secure value ['.static::describe($secure).'] is not true or false, so the driver\'s default is used.';
        }

        $timeout = Arr::get($settings, 'site.timeout');

        return new self(
            $driver,
            static::toBool($secure) ?? ($driver === 'herd' && $mode !== HerdMode::Link),
            is_numeric($timeout) ? max(0, (int) $timeout) : 60,
            $warnings,
        );
    }

    /**
     * The driver the configuration asks for: site.driver, else the legacy
     * herd value, else auto.
     */
    protected static function requested(mixed $driver, mixed $legacy, ?HerdMode $mode): string
    {
        if (static::isUnset($driver)) {
            return match (true) {
                static::isUnset($legacy) => 'auto',
                $mode === HerdMode::None => 'none',
                default => 'herd',
            };
        }

        if (! is_string($driver) || (! in_array($driver, static::drivers(), true) && ! static::isDriverClass($driver))) {
            throw WorktreeException::unknownSiteDriver(static::describe($driver), static::drivers());
        }

        return $driver;
    }

    /**
     * Whether a name is a class that can serve as a driver: one a project
     * writes for its own server.
     *
     * @phpstan-assert-if-true class-string<SiteDriver> $driver
     */
    public static function isDriverClass(string $driver): bool
    {
        return class_exists($driver) && is_a($driver, SiteDriver::class, true)
            && (new ReflectionClass($driver))->isInstantiable();
    }

    /**
     * The driver that will serve: auto picks the first installed one, and a
     * driver whose tools are missing gives way to none.
     *
     * @param  Closure(string): bool  $available
     */
    protected static function installed(string $driver, Closure $available): string
    {
        if ($driver === 'auto') {
            return match (true) {
                $available('herd') => 'herd',
                $available('valet') => 'valet',
                default => 'none',
            };
        }

        if ($driver === 'none' || $available($driver)) {
            return $driver;
        }

        return 'none';
    }

    /**
     * The resolved driver: one of drivers() but auto, or a driver class.
     */
    public function driver(): string
    {
        return $this->driver;
    }

    public function secure(): bool
    {
        return $this->secure;
    }

    /**
     * Seconds a driver command may take before it is stopped; 0 for no limit.
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * What setup reports about the configuration before it starts.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The values site.driver accepts.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return ['auto', 'herd', 'valet', 'command', 'none'];
    }

    /**
     * Why a configured driver cannot serve, for the warning setup shows.
     */
    protected static function unavailable(string $driver): string
    {
        return match ($driver) {
            'herd', 'valet' => 'its command was not found',
            'command' => 'the program in worktree.site.drivers.command.serve was not found',
            default => 'it reports its server as not available',
        };
    }

    protected static function remedy(string $driver): string
    {
        return match ($driver) {
            'herd', 'valet' => "Install it, point worktree.site.drivers.{$driver}.binary at it, or set WORKTREE_DRIVER to auto or none.",
            'command' => 'Fix that command, or set WORKTREE_DRIVER to auto or none.',
            default => 'Set its server up, or set WORKTREE_DRIVER to auto or none.',
        };
    }

    protected static function isUnset(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * env() leaves "1" and "0" as strings and turns "true" and "false" into
     * booleans, so both forms are read; anything else counts as unset.
     */
    protected static function toBool(mixed $value): ?bool
    {
        if (static::isUnset($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    protected static function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
