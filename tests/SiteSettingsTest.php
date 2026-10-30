<?php

declare(strict_types=1);

use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Drivers\Driver;
use Mozex\Worktree\Drivers\NoneDriver;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\SiteSettings;
use Mozex\Worktree\Worktree;

/**
 * A project's own driver, for the resolver to accept by its class name.
 */
final class ProjectSiteDriver extends Driver
{
    public function serve(Worktree $worktree, bool $secure, SiteRunner $runner): bool
    {
        return true;
    }

    public function unserve(string $name, string $path, bool $secure, SiteRunner $runner): void {}
}

/**
 * @param  array<string, mixed>  $settings
 * @param  list<string>  $installed  More drivers whose tools are on the machine.
 */
function resolveSite(array $settings, bool $herd = true, array $installed = []): SiteSettings
{
    return SiteSettings::resolve($settings, fn (string $driver): bool => ($driver === 'herd' && $herd) || in_array($driver, $installed, true));
}

it('picks herd over https when nothing is configured and herd is installed', function () {
    $site = resolveSite(['herd' => null, 'site' => ['driver' => null, 'secure' => null]]);

    expect($site->driver())->toBe('herd')
        ->and($site->secure())->toBeTrue()
        ->and($site->warnings())->toBe([]);
});

it('serves nothing without a word when nothing is configured and herd is missing', function () {
    $site = resolveSite(['site' => ['driver' => 'auto']], herd: false);

    expect($site->driver())->toBe('none')
        ->and($site->secure())->toBeFalse()
        ->and($site->warnings())->toBe([]);
});

it('maps each legacy herd value', function (mixed $legacy, string $driver, bool $secure) {
    $site = resolveSite(['herd' => $legacy, 'site' => ['driver' => null]]);

    expect($site->driver())->toBe($driver)
        ->and($site->secure())->toBe($secure)
        ->and($site->warnings())->toBe([]);
})->with([
    'secure' => ['secure', 'herd', true],
    'link' => ['link', 'herd', false],
    'none' => ['none', 'none', false],
]);

it('keeps an unknown legacy herd value on herd over https, with a warning', function (mixed $legacy, string $shown) {
    $site = resolveSite(['herd' => $legacy]);

    expect($site->driver())->toBe('herd')
        ->and($site->secure())->toBeTrue()
        ->and($site->warnings())->toHaveCount(1)
        ->and($site->warnings()[0])->toContain("[{$shown}]")->toContain('WORKTREE_DRIVER');
})->with([
    'a future driver name' => ['valet', 'valet'],
    'env() true' => [true, 'true'],
    'env() false' => [false, 'false'],
]);

it('lets site.driver win over the legacy herd value', function () {
    $site = resolveSite(['herd' => 'secure', 'site' => ['driver' => 'none']]);

    expect($site->driver())->toBe('none');
});

it('treats an empty driver and an empty legacy value as unset', function () {
    $site = resolveSite(['herd' => '', 'site' => ['driver' => '', 'secure' => '']]);

    expect($site->driver())->toBe('herd')
        ->and($site->secure())->toBeTrue()
        ->and($site->warnings())->toBe([]);
});

it('refuses a site driver it does not know', function (mixed $driver) {
    resolveSite(['site' => ['driver' => $driver]]);
})->with([
    'an unknown name' => ['apache'],
    'a name in the wrong case' => ['Herd'],
    'a boolean' => [true],
    'a class that is no driver' => [WorktreeException::class],
    'the abstract base driver' => [Driver::class],
])->throws(WorktreeException::class, 'Set worktree.site.driver (WORKTREE_DRIVER) to one of: auto, herd, valet, command, none, or the name of a class implementing Mozex\Worktree\Contracts\SiteDriver.');

it('picks herd, then valet, then none when set to auto', function (bool $herd, array $installed, string $driver) {
    $site = resolveSite(['site' => ['driver' => 'auto']], $herd, $installed);

    expect($site->driver())->toBe($driver)
        ->and($site->secure())->toBe($driver === 'herd')
        ->and($site->warnings())->toBe([]);
})->with([
    'both installed' => [true, ['valet'], 'herd'],
    'only valet' => [false, ['valet'], 'valet'],
    'neither' => [false, [], 'none'],
]);

it('serves through valet over http unless asked to secure', function () {
    expect(resolveSite(['site' => ['driver' => 'valet']], installed: ['valet'])->secure())->toBeFalse()
        ->and(resolveSite(['site' => ['driver' => 'valet', 'secure' => true]], installed: ['valet'])->secure())->toBeTrue();
});

it('accepts a project driver class by its name', function () {
    $site = resolveSite(['site' => ['driver' => ProjectSiteDriver::class]], installed: [ProjectSiteDriver::class]);

    expect($site->driver())->toBe(ProjectSiteDriver::class)
        ->and($site->secure())->toBeFalse()
        ->and($site->warnings())->toBe([]);
});

it('serves nothing and says why when a configured driver is not available', function (string $driver, array $settings, string $reason) {
    $site = resolveSite(['site' => ['driver' => $driver, ...$settings]], herd: false);

    expect($site->driver())->toBe('none')
        ->and($site->warnings())->toHaveCount(1)
        ->and($site->warnings()[0])->toContain("[{$driver}]")->toContain($reason)->toContain('WORKTREE_DRIVER');
})->with([
    'valet' => ['valet', [], 'worktree.site.drivers.valet.binary'],
    'command' => ['command', ['drivers' => ['command' => ['serve' => ['wt-missing-server']]]], 'worktree.site.drivers.command.serve'],
    'a class' => [NoneDriver::class, [], 'not available'],
]);

it('refuses the command driver with no command to serve', function (mixed $serve) {
    resolveSite(['site' => ['driver' => 'command', 'drivers' => ['command' => ['serve' => $serve]]]]);
})->with([
    'empty' => [[]],
    'an empty string' => [''],
    'unset' => [null],
])->throws(WorktreeException::class, 'Set worktree.site.drivers.command.serve');

it('serves nothing and says so when herd is asked for but missing', function (array $settings) {
    $site = resolveSite($settings, herd: false);

    expect($site->driver())->toBe('none')
        ->and($site->warnings())->toHaveCount(1)
        ->and($site->warnings()[0])->toContain('WORKTREE_DRIVER')->toContain('worktree.site.drivers.herd.binary');
})->with([
    'by site.driver' => [['site' => ['driver' => 'herd']]],
    'by the legacy herd value' => [['herd' => 'secure']],
]);

it('reads site.secure in the forms env() leaves it in', function (mixed $secure, bool $expected) {
    expect(resolveSite(['site' => ['driver' => 'herd', 'secure' => $secure]])->secure())->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    '"1"' => ['1', true],
    '"0"' => ['0', false],
    '"false"' => ['false', false],
]);

it('keeps the default and says so when site.secure is not a boolean', function () {
    $site = resolveSite(['site' => ['driver' => 'herd', 'secure' => 'maybe']]);

    expect($site->secure())->toBeTrue()
        ->and($site->warnings())->toHaveCount(1)
        ->and($site->warnings()[0])->toContain('[maybe]');
});

it('words the warnings so they agree when an unknown legacy value meets a missing herd', function () {
    // "Herd serves it over HTTPS" next to "it is not served" read as a contradiction.
    $warnings = resolveSite(['herd' => true], herd: false)->warnings();

    expect($warnings)->toHaveCount(2)
        ->and($warnings[0])->toContain('read as secure')->not->toContain('serves')
        ->and($warnings[1])->toContain('not served');
});

it('lets site.secure override the legacy herd value', function () {
    expect(resolveSite(['herd' => 'link', 'site' => ['secure' => true]])->secure())->toBeTrue()
        ->and(resolveSite(['herd' => 'secure', 'site' => ['secure' => false]])->secure())->toBeFalse();
});

it('keeps the legacy link value plain http when herd is picked another way', function () {
    expect(resolveSite(['herd' => 'link', 'site' => ['driver' => 'herd']])->secure())->toBeFalse();
});

it('reads a config cached by a release without site drivers', function () {
    // A cached config skips the merge that fills new keys from the defaults.
    $site = resolveSite(['herd' => 'secure', 'host' => ['tld' => 'test']]);

    expect($site->driver())->toBe('herd')
        ->and($site->secure())->toBeTrue()
        ->and($site->timeout())->toBe(60);
});

it('reads the timeout, falling back to a minute', function (mixed $timeout, int $expected) {
    expect(resolveSite(['site' => ['timeout' => $timeout]])->timeout())->toBe($expected);
})->with([
    'set' => [5, 5],
    'a string' => ['15', 15],
    'no limit' => [0, 0],
    'negative' => [-3, 0],
    'null' => [null, 60],
    'empty' => ['', 60],
]);
