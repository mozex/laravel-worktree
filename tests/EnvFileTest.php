<?php

declare(strict_types=1);

use Mozex\Worktree\Support\EnvFile;

it('reads a value', function () {
    $env = new EnvFile("APP_NAME=Blog\nDB_DATABASE=blog\n");

    expect($env->get('DB_DATABASE'))->toBe('blog')
        ->and($env->get('MISSING'))->toBeNull();
});

it('reads a quoted value without the quotes', function () {
    expect((new EnvFile('APP_NAME="My App"'))->get('APP_NAME'))->toBe('My App');
});

it('replaces an existing key in place', function () {
    $env = new EnvFile("APP_NAME=Blog\nDB_DATABASE=blog\nAPP_ENV=local\n");

    $env->set('DB_DATABASE', 'blog_feature');

    expect($env->contents())->toBe("APP_NAME=Blog\nDB_DATABASE=blog_feature\nAPP_ENV=local\n");
});

it('appends a key that does not exist', function () {
    $env = new EnvFile("APP_NAME=Blog\n");

    $env->set('DB_DATABASE', 'blog');

    expect($env->contents())->toBe("APP_NAME=Blog\nDB_DATABASE=blog\n");
});

it('quotes values that contain whitespace', function () {
    $env = new EnvFile('APP_NAME=Blog');

    $env->set('APP_NAME', 'My Blog');

    expect($env->contents())->toBe('APP_NAME="My Blog"');
});

it('lists the keys it defines', function () {
    $env = new EnvFile(implode("\n", [
        'APP_NAME=Blog',
        '# A comment',
        '',
        'DB_DATABASE=blog',
        '# DB_COMMENTED=nope',
        '  INDENTED=nope',
        'DB_PASSWORD=',
    ]));

    expect($env->keys())->toBe(['APP_NAME', 'DB_DATABASE', 'DB_PASSWORD']);
});

it('lists keys from a file with windows line endings', function () {
    expect((new EnvFile("APP_NAME=Blog\r\nDB_DATABASE=blog\r\n"))->keys())
        ->toBe(['APP_NAME', 'DB_DATABASE']);
});

it('keeps dollar signs in a value intact', function () {
    $env = new EnvFile("DB_PASSWORD=old\nAPP_ENV=local\n");

    $env->set('DB_PASSWORD', 'p$a$$word');

    expect($env->get('DB_PASSWORD'))->toBe('p$a$$word')
        ->and($env->contents())->toBe("DB_PASSWORD=p\$a\$\$word\nAPP_ENV=local\n");
});

it('reads a value from a file with windows line endings', function () {
    $env = new EnvFile("APP_NAME=Blog\r\nDB_DATABASE=blog\r\n");

    expect($env->get('DB_DATABASE'))->toBe('blog');
});

it('preserves windows line endings when replacing a key', function () {
    $env = new EnvFile("APP_NAME=Blog\r\nDB_DATABASE=blog\r\nAPP_ENV=local\r\n");

    $env->set('DB_DATABASE', 'blog_feature');

    expect($env->contents())->toBe("APP_NAME=Blog\r\nDB_DATABASE=blog_feature\r\nAPP_ENV=local\r\n");
});

it('appends with the line ending the file already uses', function () {
    $env = new EnvFile("APP_NAME=Blog\r\n");

    $env->set('DB_DATABASE', 'blog');

    expect($env->contents())->toBe("APP_NAME=Blog\r\nDB_DATABASE=blog\r\n");
});

it('remaps a host everywhere it appears', function () {
    $env = new EnvFile(implode("\n", [
        'APP_URL=https://blog.test',
        'APP_HOST=blog.test',
        'OTHER_URL=https://other.test',
        'MAIL_FROM_ADDRESS=info@blog.test',
    ]));

    $env->remapHost('blog.test', 'blog-feature.test');

    expect($env->contents())->toBe(implode("\n", [
        'APP_URL=https://blog-feature.test',
        'APP_HOST=blog-feature.test',
        'OTHER_URL=https://other.test',
        'MAIL_FROM_ADDRESS=info@blog-feature.test',
    ]));
});

it('does not remap hosts that merely contain the source host', function () {
    $env = new EnvFile(implode("\n", [
        'A=blog.testing',
        'B=sub.blog.test',
        'C=myblog.test',
        'D=blog.test',
    ]));

    $env->remapHost('blog.test', 'blog-x.test');

    expect($env->contents())->toBe(implode("\n", [
        'A=blog.testing',
        'B=sub.blog.test',
        'C=myblog.test',
        'D=blog-x.test',
    ]));
});
