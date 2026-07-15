<?php

declare(strict_types=1);

use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\PhpunitConfig;

// tempnam() would create a second, extension-less file that the cleanup glob never matches.
function phpunitPath(): string
{
    return sys_get_temp_dir().'/wt-'.bin2hex(random_bytes(4)).'.xml';
}

function phpunitFixture(string $body): string
{
    $path = phpunitPath();
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<phpunit>\n<php>\n{$body}\n</php>\n</phpunit>\n");

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/wt*.xml') ?: [] as $file) {
        @unlink($file);
    }
});

it('replaces an existing env value', function () {
    $path = phpunitFixture('<env name="DB_DATABASE" value="testing"/>');

    PhpunitConfig::fromFile($path)->setEnv('DB_DATABASE', 'blog_feature_testing')->save($path);

    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('name="DB_DATABASE"')
        ->and($contents)->toContain('value="blog_feature_testing"')
        ->and($contents)->not->toContain('value="testing"');
});

it('adds a real env entry when only a commented one exists', function () {
    $path = phpunitFixture('<!-- <env name="DB_DATABASE" value=":memory:"/> -->');

    PhpunitConfig::fromFile($path)->setEnv('DB_DATABASE', 'blog_testing')->save($path);

    $document = new DOMDocument;
    $document->load($path);
    $values = [];

    foreach ($document->getElementsByTagName('env') as $env) {
        $values[] = $env->getAttribute('value');
    }

    expect($values)->toBe(['blog_testing']);
});

it('reads an env value', function () {
    $path = phpunitFixture('<env name="DB_CONNECTION" value="sqlite"/>');

    $config = PhpunitConfig::fromFile($path);

    expect($config->env('DB_CONNECTION'))->toBe('sqlite')
        ->and($config->env('DB_DATABASE'))->toBeNull();
});

it('ignores a commented out env value', function () {
    $path = phpunitFixture('<!-- <env name="DB_CONNECTION" value="sqlite"/> -->');

    expect(PhpunitConfig::fromFile($path)->env('DB_CONNECTION'))->toBeNull();
});

it('refuses to rewrite a malformed file instead of emptying it', function () {
    $path = phpunitPath();
    $original = "<?xml version=\"1.0\"?>\n<phpunit><php>\n";
    file_put_contents($path, $original);

    expect(fn () => PhpunitConfig::fromFile($path))->toThrow(WorktreeException::class);
    expect((string) file_get_contents($path))->toBe($original);
});

it('creates the php block when it is missing', function () {
    $path = phpunitPath();
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<phpunit></phpunit>\n");

    PhpunitConfig::fromFile($path)->setEnv('DB_DATABASE', 'blog_testing')->save($path);

    expect((string) file_get_contents($path))->toContain('<env name="DB_DATABASE" value="blog_testing"/>');
});
