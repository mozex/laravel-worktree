<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mozex\Worktree\Exceptions\WorktreeException;
use Mozex\Worktree\Support\Registry;

/**
 * @return array<string, mixed>
 */
function registryEntry(string $path = '/code/blog-feature', array $overrides = []): array
{
    return array_merge([
        'path' => $path,
        'name' => basename($path),
        'branch' => 'feature',
        'tool' => 'setup',
        'driver' => 'herd',
        'secure' => true,
        'host' => basename($path).'.test',
        'url' => 'https://'.basename($path).'.test',
        'databases' => [['connection' => 'mysql', 'database' => 'blog_feature', 'dsn' => 'mysql:host=127.0.0.1;port=3306', 'test' => false]],
        'files' => [],
        'created' => '2026-10-30T10:00:00+00:00',
    ], $overrides);
}

function registryDirectory(): string
{
    return sys_get_temp_dir().'/wt-registry-'.getmypid();
}

afterEach(function () {
    (new Filesystem)->deleteDirectory(registryDirectory());
});

it('finds, replaces and forgets an entry by its path', function () {
    $registry = new Registry;
    $registry->put(registryEntry('/code/blog-feature'));
    $registry->put(registryEntry('/code/blog-search'));

    expect($registry->find('/code/blog-feature')['name'])->toBe('blog-feature')
        ->and($registry->find('/code/blog-other'))->toBeNull();

    $registry->put(registryEntry('/code/blog-feature', ['driver' => 'valet']));

    expect($registry->entries())->toHaveCount(2)
        ->and($registry->find('/code/blog-feature')['driver'])->toBe('valet');

    $registry->forget('/code/blog-feature');

    expect($registry->find('/code/blog-feature'))->toBeNull()
        ->and($registry->entries())->toHaveCount(1);
});

it('matches a path with either slash and a trailing one', function () {
    $registry = new Registry;
    $registry->put(registryEntry('C:/code/blog-feature'));

    expect($registry->find('C:\\code\\blog-feature\\'))->not->toBeNull();
});

it('matches a path without case only where the filesystem ignores it', function () {
    $registry = new Registry;
    $registry->put(registryEntry('/code/blog-feature'));

    $ignoresCase = in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true);

    expect($registry->find('/code/Blog-Feature') !== null)->toBe($ignoresCase);
});

it('keeps what an earlier run recorded when a resumed setup records again', function () {
    $registry = new Registry;
    $registry->put(registryEntry(overrides: [
        'files' => [['path' => '/data/blog_feature.sqlite', 'source' => '/data/blog.sqlite']],
    ]));

    // A resumed run with --no-database records no databases of its own.
    $registry->put(registryEntry(overrides: [
        'databases' => [],
        'files' => [],
        'created' => '2026-10-31T10:00:00+00:00',
    ]));

    $entry = $registry->find('/code/blog-feature');

    expect($entry['databases'])->toHaveCount(1)
        ->and($entry['databases'][0]['database'])->toBe('blog_feature')
        ->and($entry['files'])->toBe([['path' => '/data/blog_feature.sqlite', 'source' => '/data/blog.sqlite']])
        ->and($entry['created'])->toBe('2026-10-30T10:00:00+00:00');
});

it('forgets that a worktree was provisioned once a run records it again', function () {
    $registry = new Registry;
    $registry->put(registryEntry(overrides: ['provisioned' => '2026-10-30T10:05:00+00:00']));

    expect($registry->find('/code/blog-feature')['provisioned'])->toBe('2026-10-30T10:05:00+00:00');

    // A resumed run records the worktree as soon as it starts, and marks it
    // provisioned only when it finishes.
    $registry->put(registryEntry());

    expect($registry->find('/code/blog-feature')['provisioned'])->toBeNull();
});

it('records a database once however often it is recorded', function () {
    $registry = new Registry;
    $registry->put(registryEntry());
    $registry->put(registryEntry());

    expect($registry->find('/code/blog-feature')['databases'])->toHaveCount(1);
});

it('saves and reads back what it holds', function () {
    $file = registryDirectory().'/nested/registry.json';

    $registry = new Registry;
    $registry->put(registryEntry());
    $registry->save($file);

    expect(Registry::fromFile($file)->entries())->toBe($registry->entries())
        ->and(glob(dirname($file).'/*.tmp'))->toBe([])
        ->and(json_decode((string) file_get_contents($file), true)['version'])->toBe(1);
});

it('reads a missing file as an empty registry', function () {
    expect(Registry::fromFile(registryDirectory().'/registry.json')->entries())->toBe([]);
});

it('refuses a file it cannot read rather than treating it as empty', function (string $contents) {
    expect(fn () => Registry::fromJson($contents))->toThrow(WorktreeException::class, 'could not be read');
})->with([
    'not json' => ['{"version": 1, "worktrees": ['],
    'no worktrees' => ['{"version": 1}'],
    'a newer format' => ['{"version": 2, "worktrees": []}'],
]);

it('skips an entry with no path and fills in what an entry leaves out', function () {
    $registry = Registry::fromJson((string) json_encode([
        'version' => 1,
        'worktrees' => [
            ['name' => 'no-path'],
            'not an entry',
            ['path' => 'C:\\code\\blog-feature', 'databases' => [['database' => 'blog_feature'], ['database' => 'blog_x', 'dsn' => 'mysql:host=db']]],
        ],
    ]));

    expect($registry->entries())->toHaveCount(1)
        ->and($registry->entries()[0])->toMatchArray([
            'path' => 'C:/code/blog-feature',
            'name' => 'blog-feature',
            'branch' => null,
            'driver' => 'none',
            'secure' => false,
            'url' => null,
            'databases' => [['connection' => '', 'database' => 'blog_x', 'dsn' => 'mysql:host=db', 'test' => false]],
        ]);
});

it('reads an entry recorded before sites had names of their own as served under its name', function () {
    $registry = Registry::fromJson((string) json_encode(['version' => 1, 'worktrees' => [
        ['path' => '/code/blog-Feature_X', 'name' => 'blog-Feature_X'],
        ['path' => '/code/blog-feature-y', 'name' => 'blog-feature-y', 'site' => 'feature-y.blog'],
        ['path' => '/code/blog-feature-z', 'name' => 'blog-feature-z', 'site' => ''],
    ]]));

    expect(array_column($registry->entries(), 'site'))->toBe(['blog-Feature_X', 'feature-y.blog', 'blog-feature-z']);
});

it('keeps the keys a later release records', function () {
    $registry = Registry::fromJson((string) json_encode(['version' => 1, 'worktrees' => [registryEntry(overrides: ['ports' => [8001]])]]));

    expect(json_decode($registry->toJson(), true)['worktrees'][0]['ports'])->toBe([8001]);
});

it('reports a registry it cannot encode as unwritable', function () {
    $registry = new Registry;
    $registry->put(registryEntry(overrides: ['branch' => "feature-\xB1"]));

    expect(fn () => $registry->save(registryDirectory().'/registry.json'))
        ->toThrow(WorktreeException::class, 'could not be written');
});
