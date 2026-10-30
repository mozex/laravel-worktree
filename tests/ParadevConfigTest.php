<?php

declare(strict_types=1);

use Mozex\Worktree\Support\ParadevConfig;

/**
 * The command the installer writes, as Paradev's local config holds it.
 */
function paradevCommand(): string
{
    return 'php worktree-adopt.php --tool=paradev --no-secure';
}

it('refuses a config whose setup it cannot read', function (string $json) {
    expect(ParadevConfig::parse($json))->toBeNull();
})->with([
    'not JSON' => ['{"setup": '],
    'a list' => ['[]'],
    'a setup that is a string' => ['{"setup": "bun install"}'],
    'a setup with something that is not a command' => ['{"setup": ["bun install", 3]}'],
    'an after that is not a list' => ['{"setup": {"after": "bun install"}}'],
]);

it('reads a missing file as an empty config', function () {
    $config = ParadevConfig::fromFile(sys_get_temp_dir().'/wt-no-such-config-'.bin2hex(random_bytes(4)).'.json');

    $config?->install(paradevCommand());

    expect($config?->toJson())->toBe("{\n  \"setup\": {\n    \"after\": [\n      \"".paradevCommand()."\"\n    ]\n  }\n}\n");
});

it('runs the command after the repository\'s setup commands', function (string $json, array $setup) {
    $config = ParadevConfig::parse($json);

    $config?->install(paradevCommand());

    expect(json_decode((string) $config?->toJson(), true)['setup'])->toBe($setup);
})->with([
    'no setup of its own' => ['{"run": ["bun dev"]}', ['after' => ['php worktree-adopt.php --tool=paradev --no-secure']]],
    'commands around the repository\'s' => ['{"setup": {"before": ["a"], "after": ["b"]}}', ['before' => ['a'], 'after' => ['b', 'php worktree-adopt.php --tool=paradev --no-secure']]],
    'commands in place of the repository\'s' => ['{"setup": ["a", "b"]}', ['a', 'b', 'php worktree-adopt.php --tool=paradev --no-secure']],
    'an earlier run of its own' => ['{"setup": {"after": ["php worktree-adopt.php --tool=paradev", "b"]}}', ['after' => ['b', 'php worktree-adopt.php --tool=paradev --no-secure']]],
]);

it('saves the config whole', function () {
    $directory = sys_get_temp_dir().'/wt-paradev-'.bin2hex(random_bytes(4));
    $file = $directory.'/.superset/config.local.json';
    $config = ParadevConfig::parse('{"env": {}, "setup": []}');

    $config?->install(paradevCommand());

    try {
        expect($config?->save($file))->toBeTrue()
            ->and((string) file_get_contents($file))->toBe("{\n  \"env\": {},\n  \"setup\": [\n    \"".paradevCommand()."\"\n  ]\n}\n")
            ->and(glob(dirname($file).'/*.tmp') ?: [])->toBe([]);
    } finally {
        @unlink($file);
        @rmdir(dirname($file));
        @rmdir($directory);
    }
});
