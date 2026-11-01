<?php

declare(strict_types=1);

use Mozex\Worktree\Worktree;

function makeWorktree(string $source, string $branch, array $overrides = []): Worktree
{
    $config = array_replace_recursive([
        'path' => '..',
        'host' => ['template' => '{repo}-{branch}', 'tld' => 'test'],
    ], $overrides);

    return Worktree::make($source, $branch, $config);
}

it('derives names and hosts from the branch', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/login');

    expect($worktree->repository())->toBe('blog')
        ->and($worktree->branch())->toBe('feature/login')
        ->and($worktree->branchSlug())->toBe('feature-login')
        ->and($worktree->name())->toBe('blog-feature-login')
        ->and($worktree->host())->toBe('blog-feature-login.test')
        ->and($worktree->sourceHost())->toBe('blog.test');
});

it('builds database names that are safe for mysql and postgres', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/Login-Form.v2');

    expect($worktree->slug())->toBe('blog_feature_login_form_v2')
        ->and($worktree->database('{slug}'))->toBe('blog_feature_login_form_v2')
        ->and($worktree->database('{slug}_testing'))->toBe('blog_feature_login_form_v2_testing');
});

it('expands the worktree tokens in a template', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/login');

    expect($worktree->expand('{repo}'))->toBe('blog')
        ->and($worktree->expand('{branch}'))->toBe('feature-login')
        ->and($worktree->expand('{name}'))->toBe('blog-feature-login')
        ->and($worktree->expand('{slug}'))->toBe('blog_feature_login')
        ->and($worktree->expand('{host}'))->toBe('blog-feature-login.test')
        ->and($worktree->expand('{tld}'))->toBe('test')
        ->and($worktree->expand('{slug}_cache_'))->toBe('blog_feature_login_cache_');
});

it('expands a per-call value in a template', function () {
    // The {value} token carries the env key's current value, so a prefix can be
    // appended (or prepended) without the config restating it.
    $worktree = makeWorktree('/work/www/blog', 'feature/login');

    expect($worktree->expand('{value}{slug}_', ['value' => 'laravel_database_']))
        ->toBe('laravel_database_blog_feature_login_')
        ->and($worktree->expand('{slug}_{value}', ['value' => 'laravel_database_']))
        ->toBe('blog_feature_login_laravel_database_')
        ->and($worktree->expand('{value}{slug}_', ['value' => '']))
        ->toBe('blog_feature_login_');
});

it('places the worktree next to the source repository by default', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login')->path())
        ->toBe('/work/www/blog-feature-login');
});

it('supports a nested worktree directory', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login', ['path' => '.worktrees'])->path())
        ->toBe('/work/www/blog/.worktrees/blog-feature-login');
});

it('supports an absolute worktree directory', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login', ['path' => '/tmp/trees'])->path())
        ->toBe('/tmp/trees/blog-feature-login');
});

it('normalizes windows source paths', function () {
    $worktree = makeWorktree('C:\\code\\blog', 'feature/login');

    expect($worktree->repository())->toBe('blog')
        ->and($worktree->path())->toBe('C:/code/blog-feature-login');
});

it('honors a custom host template', function () {
    $worktree = makeWorktree('/work/www/blog', 'main', [
        'host' => ['template' => 'wt-{branch}-{repo}', 'tld' => 'localhost'],
    ]);

    expect($worktree->host())->toBe('wt-main-blog.localhost');
});

it('maps a path inside the source repository onto the worktree', function () {
    $worktree = Worktree::make('/sites/blog', 'feature/login', []);

    expect($worktree->mapPath('/sites/blog/database/database.sqlite'))
        ->toBe('/sites/blog-feature-login/database/database.sqlite')
        ->and($worktree->mapPath('/sites/blog'))
        ->toBe('/sites/blog-feature-login');
});

it('maps a windows path inside the source repository', function () {
    $worktree = Worktree::make('C:\Sites\blog', 'feature/login', []);

    expect($worktree->mapPath('C:\Sites\blog\database\database.sqlite'))
        ->toBe('C:/Sites/blog-feature-login/database/database.sqlite');
});

it('refuses to map a path outside the source repository', function () {
    $worktree = Worktree::make('/sites/blog', 'feature/login', []);

    expect($worktree->mapPath('/var/data/shared.sqlite'))->toBeNull()
        ->and($worktree->mapPath('/sites/blog-other/db.sqlite'))->toBeNull();
});

it('expands any template into a database name', function () {
    $worktree = makeWorktree('/work/www/blog', 'main');

    expect($worktree->database('wt_{slug}'))->toBe('wt_blog_main')
        ->and($worktree->database('{slug}_analytics'))->toBe('blog_main_analytics');
});

it('caps database names at the server identifier limit', function () {
    // MySQL rejects names over 64 characters and Postgres truncates at 63,
    // after which the full name in phpunit.xml could never be connected to.
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $worktree = makeWorktree('/work/www/blog', $branch);

    expect(mb_strlen($worktree->database('{slug}')))->toBeLessThanOrEqual(63)
        ->and(mb_strlen($worktree->database('{slug}_testing')))->toBeLessThanOrEqual(63)
        ->and($worktree->database('{slug}'))->toBe(makeWorktree('/work/www/blog', $branch)->database('{slug}'));
});

it('keeps two truncated branches on distinct databases', function () {
    $shared = 'feature/'.str_repeat('long-branch-segment-', 4);

    expect(makeWorktree('/work/www/blog', $shared.'one')->database('{slug}'))
        ->not->toBe(makeWorktree('/work/www/blog', $shared.'two')->database('{slug}'));
});

it('caps the name so the host fits a certificate', function () {
    // "herd secure" cannot issue a certificate for a host over 64 characters,
    // and a DNS label stops at 63, so a long branch has to be cut.
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $worktree = makeWorktree('/work/www/blog', $branch);

    expect(mb_strlen($worktree->host()))->toBeLessThanOrEqual(63)
        ->and($worktree->host())->toBe($worktree->name().'.test')
        ->and($worktree->path())->toBe('/work/www/'.$worktree->name())
        ->and($worktree->name())->toBe(makeWorktree('/work/www/blog', $branch)->name())
        ->and(makeWorktree('/work/www/blog', 'feature/'.str_repeat('long-branch-segment-', 4).'one')->name())
        ->not->toBe(makeWorktree('/work/www/blog', 'feature/'.str_repeat('long-branch-segment-', 4).'two')->name());
});

it('leaves a name that already fits alone', function () {
    expect(makeWorktree('/work/www/blog', 'feature/login', ['host' => ['tld' => 'localhost']])->host())
        ->toBe('blog-feature-login.localhost');
});

it('caps the name against a longer top-level domain', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/'.str_repeat('segment-', 8), ['host' => ['tld' => 'localhost']]);

    expect(mb_strlen($worktree->host()))->toBeLessThanOrEqual(63);
});

it('keeps the uncapped name for a worktree made before the cap', function () {
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $worktree = makeWorktree('/work/www/blog', $branch);
    $legacy = $worktree->withName($worktree->uncappedName());

    expect($worktree->uncappedName())->toBe('blog-'.str_replace('/', '-', $branch))
        ->and($worktree->name())->not->toBe($worktree->uncappedName())
        ->and($legacy->name())->toBe($worktree->uncappedName())
        ->and($legacy->path())->toBe('/work/www/'.$worktree->uncappedName())
        ->and($legacy->slug())->toBe(mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $worktree->uncappedName())));
});

it('keeps a directory another tool chose, whatever it is named', function () {
    $worktree = makeWorktree('/work/www/blog', 'bold-oak')->withPath('C:\\tools\\worktrees\\a1b2\\');
    $renamed = $worktree->withName('blog-agent');

    expect($worktree->path())->toBe('C:/tools/worktrees/a1b2')
        ->and($worktree->name())->toBe('blog-bold-oak')
        ->and($worktree->host())->toBe('blog-bold-oak.test')
        ->and($worktree->mapPath('/work/www/blog/database/app.sqlite'))->toBe('C:/tools/worktrees/a1b2/database/app.sqlite')
        ->and($renamed->path())->toBe('C:/tools/worktrees/a1b2')
        ->and($renamed->name())->toBe('blog-agent')
        ->and($renamed->withPath('/elsewhere')->name())->toBe('blog-agent');
});

it('serves a branch with capitals and underscores at a hostname-safe site, keeping its name', function () {
    // A host may hold only letters, digits and hyphens in each label, and
    // servers compare it lowercased, but the directory and the databases keep
    // the name they have always had.
    $worktree = makeWorktree('/work/www/blog', 'feature/Login_Form');

    expect($worktree->name())->toBe('blog-feature-Login_Form')
        ->and($worktree->path())->toBe('/work/www/blog-feature-Login_Form')
        ->and($worktree->slug())->toBe('blog_feature_login_form')
        ->and($worktree->site())->toBe('blog-feature-login-form')
        ->and($worktree->host())->toBe('blog-feature-login-form.test')
        ->and($worktree->expand('{site} {host}'))->toBe('blog-feature-login-form blog-feature-login-form.test');
});

it('makes each part of a site hostname-safe', function (string $branch, string $template, string $site) {
    expect(makeWorktree('/work/www/blog', $branch, ['host' => ['site' => $template]])->site())->toBe($site);
})->with([
    'a clean name, unchanged' => ['feature/login', '{name}', 'blog-feature-login'],
    'dots kept between parts' => ['release-1.2', '{name}', 'blog-release-1.2'],
    'runs collapsed, ends trimmed' => ['--Fix__The--Bug!', '{name}', 'blog-fix-the-bug'],
    'a subdomain of the main site' => ['feature/Login', '{branch}.{repo}', 'feature-login.blog'],
    'empty parts dropped' => ['feature/login', '{branch}..{repo}.', 'feature-login.blog'],
    'an empty template' => ['feature/login', ' ', 'blog-feature-login'],
]);

it('serves a worktree under the main site with a subdomain template', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/login', ['host' => ['site' => '{branch}.{repo}']]);

    expect($worktree->name())->toBe('blog-feature-login')
        ->and($worktree->path())->toBe('/work/www/blog-feature-login')
        ->and($worktree->database('{slug}'))->toBe('blog_feature_login')
        ->and($worktree->host())->toBe('feature-login.blog.test')
        ->and($worktree->isUnderSourceHost())->toBeTrue()
        ->and(makeWorktree('/work/www/blog', 'feature/login')->isUnderSourceHost())->toBeFalse()
        ->and(makeWorktree('/work/www/Blog', 'feature/login', ['host' => ['site' => '{branch}.{repo}']])->isUnderSourceHost())->toBeTrue();
});

it('never gives a worktree the main site', function (string $branch, string $template) {
    // A branch with nothing a host can hold would otherwise leave the main
    // site's own name, and teardown would then take the main site down.
    $worktree = makeWorktree('/work/www/blog', $branch, ['host' => ['site' => $template]]);

    expect($worktree->site())->toMatch('/^blog-[0-9a-f]{6}$/')
        ->and($worktree->site())->toBe(makeWorktree('/work/www/blog', $branch, ['host' => ['site' => $template]])->site());
})->with([
    'a branch of symbols' => ['___', '{name}'],
    'a branch of other scripts' => ['ویژگی', '{name}'],
    'a template of the repository alone' => ['feature/login', '{repo}'],
    'a template that leaves nothing' => ['___', '{branch}'],
]);

it('caps a long site so its host fits a certificate', function () {
    $branch = 'feature/'.str_repeat('long-branch-segment-', 4).'end';
    $subdomain = makeWorktree('/work/www/blog', $branch, ['host' => ['site' => '{branch}.{repo}']]);
    $other = makeWorktree('/work/www/blog', $branch.'-two', ['host' => ['site' => '{branch}.{repo}']]);

    expect(strlen($subdomain->host()))->toBe(63)
        ->and($subdomain->host())->toEndWith('.blog.test')
        ->and($subdomain->host())->toMatch('/^[a-z0-9-]+-[0-9a-f]{6}\.blog\.test$/')
        ->and($other->host())->not->toBe($subdomain->host())
        ->and(strlen(makeWorktree('/work/www/'.str_repeat('r', 50), $branch, ['host' => ['site' => '{branch}.{repo}']])->host()))
        ->toBeLessThanOrEqual(63);

    // A branch with dots makes more than one part in front of the main
    // site's, and the cut keeps the main site's whole.
    $dotted = makeWorktree('/work/www/blog', 'release/2.3.0-backport-of-the-long-feature-description-here', ['host' => ['site' => '{branch}.{repo}']]);

    expect(strlen($dotted->host()))->toBeLessThanOrEqual(63)
        ->and($dotted->host())->toEndWith('.blog.test')
        ->and($dotted->host())->toStartWith('release-2.3.0-backport')
        ->and($dotted->isUnderSourceHost())->toBeTrue();
});

it('keeps the site a worktree was first served as', function () {
    $worktree = makeWorktree('/work/www/blog', 'feature/Login_Form')->withSite('blog-feature-Login_Form');

    expect($worktree->host())->toBe('blog-feature-Login_Form.test')
        ->and($worktree->expand('{site}'))->toBe('blog-feature-Login_Form')
        ->and($worktree->withName('blog-other')->site())->toBe('blog-feature-Login_Form')
        ->and($worktree->withPath('/elsewhere')->site())->toBe('blog-feature-Login_Form')
        ->and($worktree->withName('blog-other')->name())->toBe('blog-other');
});
