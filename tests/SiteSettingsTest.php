<?php

declare(strict_types=1);

use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\SiteSettings;

/**
 * @param  array<string, mixed>  $settings
 */
function resolveSite(array $settings, bool $herd = true): SiteSettings
{
    return SiteSettings::resolve($settings, fn (string $driver): bool => $driver === 'herd' && $herd);
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
})->with(['valet', 'Herd', true])->throws(WorktreeException::class, 'Set worktree.site.driver (WORKTREE_DRIVER) to one of: auto, herd, none.');

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
