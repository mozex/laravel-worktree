<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mozex\Worktree\Support\Directory;

function sandbox(): string
{
    $base = sys_get_temp_dir().'/wt-dir-'.bin2hex(random_bytes(4));
    mkdir($base.'/tree/nested', 0777, true);
    mkdir($base.'/outside/data', 0777, true);
    file_put_contents($base.'/tree/nested/file.txt', "inside\n");
    file_put_contents($base.'/outside/data/keep.txt', "outside\n");

    return $base;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/wt-dir-*') ?: [] as $leftover) {
        Directory::delete($leftover);
    }
});

it('deletes a directory and its contents', function () {
    $base = sandbox();

    expect(Directory::delete($base.'/tree'))->toBeTrue()
        ->and(is_dir($base.'/tree'))->toBeFalse();
});

it('removes a link without following it', function () {
    $base = sandbox();

    // Mirrors "php artisan storage:link": a symlink on unix, a junction on Windows.
    (new Filesystem)->link($base.'/outside/data', $base.'/tree/linked');

    expect(Directory::delete($base.'/tree'))->toBeTrue()
        ->and(is_dir($base.'/tree'))->toBeFalse()
        ->and(file_get_contents($base.'/outside/data/keep.txt'))->toBe("outside\n");
});

it('knows an empty directory from one with something in it', function () {
    $base = sandbox();
    mkdir($base.'/blank');

    expect(Directory::isEmpty($base.'/blank'))->toBeTrue()
        ->and(Directory::isEmpty($base.'/tree'))->toBeFalse()
        ->and(Directory::isEmpty($base.'/missing'))->toBeFalse();
});

it('reports success for a path that is already gone', function () {
    expect(Directory::delete(sys_get_temp_dir().'/wt-dir-missing-'.bin2hex(random_bytes(4))))->toBeTrue();
});
