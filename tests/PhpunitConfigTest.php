<?php

declare(strict_types=1);

use Mozex\Worktree\Support\PhpunitConfig;

function phpunitFixture(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'wt').'.xml';
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

it('creates the php block when it is missing', function () {
    $path = tempnam(sys_get_temp_dir(), 'wt').'.xml';
    file_put_contents($path, "<?xml version=\"1.0\"?>\n<phpunit></phpunit>\n");

    PhpunitConfig::fromFile($path)->setEnv('DB_DATABASE', 'blog_testing')->save($path);

    expect((string) file_get_contents($path))->toContain('<env name="DB_DATABASE" value="blog_testing"/>');
});
