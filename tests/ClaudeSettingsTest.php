<?php

declare(strict_types=1);

use Mozex\Worktree\Support\ClaudeSettings;

/**
 * A handler like the one the installer writes.
 *
 * @return array<string, mixed>
 */
function ourHandler(string $event, string $php = '/usr/bin/php'): array
{
    return [
        'type' => 'command',
        'command' => $php,
        'args' => ['-d', 'display_errors=stderr', '/code/blog/artisan', 'worktree:hook', 'claude', $event],
        'timeout' => 1800,
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function ourHandlers(string $php = '/usr/bin/php'): array
{
    return ['WorktreeCreate' => ourHandler('create', $php), 'WorktreeRemove' => ourHandler('remove', $php)];
}

it('refuses settings it cannot read the hooks of', function (string $json) {
    expect(ClaudeSettings::parse($json))->toBeNull();
})->with([
    'not JSON' => ['{"hooks": '],
    'a list' => ['[]'],
    'hooks that are not an object' => ['{"hooks": []}'],
    'an event that is not a list' => ['{"hooks": {"WorktreeCreate": {}}}'],
]);

it('reads a missing file as no settings', function () {
    $settings = ClaudeSettings::fromFile(sys_get_temp_dir().'/wt-no-such-settings-'.bin2hex(random_bytes(4)).'.json');

    expect($settings)->not->toBeNull()
        ->and($settings?->hookedEvents())->toBe([]);
});

it('adds its hooks and leaves every other setting as it was', function () {
    $json = <<<'JSON'
    {
      "env": {},
      "permissions": {
        "allow": ["Bash(composer test)"]
      },
      "hooks": {
        "PreToolUse": [
          {
            "matcher": "Bash",
            "hooks": [{"type": "command", "command": "./check.sh"}]
          }
        ]
      }
    }
    JSON;

    $settings = ClaudeSettings::parse($json);
    $settings?->install(ourHandlers());
    $written = (string) $settings?->toJson();
    $decoded = json_decode($written, true);

    // An empty object would come back as an empty list from an array decode.
    expect($written)->toContain('"env": {}')
        ->and($decoded['permissions'])->toBe(['allow' => ['Bash(composer test)']])
        ->and($decoded['hooks']['PreToolUse'])->toBe([['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => './check.sh']]]])
        ->and($decoded['hooks']['WorktreeCreate'])->toBe([['hooks' => [ourHandler('create')]]])
        ->and($decoded['hooks']['WorktreeRemove'])->toBe([['hooks' => [ourHandler('remove')]]])
        // Indented as Claude Code writes its own settings.
        ->and($written)->toStartWith("{\n  \"env\"");
});

it('replaces the hooks it wrote before instead of adding more', function () {
    $settings = new ClaudeSettings;
    $settings->install(ourHandlers('/old/php'));
    $first = $settings->toJson();

    $again = ClaudeSettings::parse($first);
    $again?->install(ourHandlers('/old/php'));

    expect($again?->toJson())->toBe($first);

    $again?->install(ourHandlers('/new/php'));
    $decoded = json_decode((string) $again?->toJson(), true);

    expect($decoded['hooks']['WorktreeCreate'])->toBe([['hooks' => [ourHandler('create', '/new/php')]]]);
});

it('takes a shell command running worktree:hook for its own', function () {
    $settings = ClaudeSettings::parse((string) json_encode(['hooks' => [
        'WorktreeCreate' => [['hooks' => [['type' => 'command', 'command' => 'php artisan worktree:hook claude create']]]],
    ]]));

    expect($settings?->foreignEvents())->toBe([])
        ->and($settings?->hookedEvents())->toBe(['WorktreeCreate']);

    $settings?->install(ourHandlers());

    expect(json_decode((string) $settings?->toJson(), true)['hooks']['WorktreeCreate'])->toBe([['hooks' => [ourHandler('create')]]]);
});

it('names the events holding a hook that is not its own', function () {
    $settings = ClaudeSettings::parse((string) json_encode(['hooks' => [
        'WorktreeCreate' => [['hooks' => [ourHandler('create')]]],
        'WorktreeRemove' => [['hooks' => [['type' => 'http', 'url' => 'http://localhost:8080/remove']]]],
    ]]));

    expect($settings?->foreignEvents())->toBe(['WorktreeRemove'])
        ->and($settings?->hookedEvents())->toBe(['WorktreeCreate', 'WorktreeRemove']);
});

it('saves the file in place, making its directory', function () {
    $directory = sys_get_temp_dir().'/wt-claude-settings-'.bin2hex(random_bytes(4));
    $file = $directory.'/.claude/settings.local.json';

    try {
        $settings = new ClaudeSettings;
        $settings->install(ourHandlers());

        expect($settings->save($file))->toBeTrue()
            ->and((string) file_get_contents($file))->toBe($settings->toJson())
            ->and(glob(dirname($file).'/*.tmp') ?: [])->toBe([]);
    } finally {
        @unlink($file);
        @rmdir($directory.'/.claude');
        @rmdir($directory);
    }
});
