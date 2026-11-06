<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

// Laravel Boost reads a skill's frontmatter with this pattern and Symfony's YAML parser. A skill that fails to parse,
// or lacks a name or description, is skipped on boost:update and the app keeps its old copy, so an unquoted
// description holding ": " ships a skill no app ever receives.
it('ships Boost skills whose frontmatter Boost can read', function (string $file): void {
    $content = (string) file_get_contents($file);

    expect(preg_match('/^\s*---[^\S\r\n]*\R(.*?)\R---[^\S\r\n]*(?:\R|$)/s', $content, $matches))->toBe(1);

    $frontmatter = Yaml::parse($matches[1]);

    expect($frontmatter)->toBeArray()
        ->and($frontmatter['name'] ?? null)->toBe(basename(dirname($file)))
        ->and($frontmatter['description'] ?? null)->toBeString()->not->toBeEmpty();
})->with(function (): array {
    $files = glob(dirname(__DIR__).'/resources/boost/skills/*/SKILL.md') ?: [];

    expect($files)->not->toBeEmpty();

    return array_combine(array_map(fn (string $file): string => basename(dirname($file)), $files), $files);
});
