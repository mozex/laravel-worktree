<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Sleep;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Drivers\CommandDriver;
use Mozex\Worktree\Drivers\HerdDriver;
use Mozex\Worktree\Drivers\NoneDriver;
use Mozex\Worktree\Drivers\ValetDriver;
use Mozex\Worktree\Worktree;

/**
 * Records each command a driver hands over, with where it would run, and
 * answers from canned results (null for a command that did not run to its
 * end). A result is keyed by the arguments after the binary ("links --json"),
 * or by the subcommand alone ("links") to answer every form of it; a list
 * answers successive calls in turn, its last entry repeating. Anything not
 * canned succeeds with no output.
 */
final class RecordingRunner implements SiteRunner
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  array<string, ProcessResult|list<ProcessResult|null>|null>  $results
     */
    public function __construct(protected array $results = []) {}

    public function run(array $command, ?string $path = null): ?ProcessResult
    {
        $this->calls[] = implode(' ', $command).' in '.($path ?? 'the main repository');

        $arguments = implode(' ', array_slice($command, 1));
        $key = array_key_exists($arguments, $this->results) ? $arguments : ($command[1] ?? '');

        if (! array_key_exists($key, $this->results)) {
            return new FakeProcessResult(implode(' ', $command));
        }

        if (! is_array($this->results[$key])) {
            return $this->results[$key];
        }

        return count($this->results[$key]) > 1 ? array_shift($this->results[$key]) : $this->results[$key][0];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}

/**
 * A directory that exists, standing in for a worktree.
 */
function existingWorktreeDirectory(): string
{
    return str_replace('\\', '/', sys_get_temp_dir());
}

/**
 * The worktree named blog-feature, at /sites/blog-feature.
 */
function blogFeature(): Worktree
{
    return Worktree::make('/sites/blog', 'feature', ['host' => ['template' => '{repo}-{branch}', 'tld' => 'test']]);
}

/**
 * What "herd links --json" prints for the given sites.
 */
function herdLinks(string ...$sites): FakeProcessResult
{
    return new FakeProcessResult('herd links --json', 0, (string) json_encode(array_map(fn (string $site): array => [
        'site' => $site,
        'secured' => '',
        'url' => "http://{$site}.test",
        'path' => "C:\\sites\\{$site}",
        'phpVersion' => '8.4',
    ], $sites)));
}

/**
 * What a Valet-style "links" command prints as a table for the given sites.
 */
function linksTable(string ...$sites): FakeProcessResult
{
    $rows = array_map(fn (string $site): string => "| {$site} |  X  | https://{$site}.test | /sites/{$site} | 8.4 |", $sites);

    return new FakeProcessResult('links', 0, implode("\n", ['+------+-----+-----+------+-----+', '| Site | SSL | URL | Path | PHP |', ...$rows]));
}

it('links a herd site, checks herd lists it, then secures it by name', function () {
    $runner = new RecordingRunner(['links --json' => herdLinks('blog', 'blog-feature')]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeTrue()
        ->and($runner->calls)->toBe([
            'herd link blog-feature in /sites/blog-feature',
            'herd links --json in the main repository',
            'herd secure blog-feature in /sites/blog-feature',
        ])
        ->and($runner->warnings)->toBe([]);
});

it('runs the configured herd binary, and only links a site served over http', function () {
    $runner = new RecordingRunner(['links --json' => herdLinks('blog-feature')]);

    (new HerdDriver('/opt/herd/bin/herd'))->serve(blogFeature(), false, $runner);

    expect($runner->calls)->toBe([
        '/opt/herd/bin/herd link blog-feature in /sites/blog-feature',
        '/opt/herd/bin/herd links --json in the main repository',
    ]);
});

it('stops serving at the first herd command that fails', function (?ProcessResult $link, array $warnings) {
    $runner = new RecordingRunner(['link' => $link]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and($runner->calls)->toBe(['herd link blog-feature in /sites/blog-feature'])
        ->and($runner->warnings)->toBe($warnings);
})->with([
    'failed' => [new FakeProcessResult('herd link', 1), ['Could not run [herd link blog-feature]. The site may need to be served manually.']],
    // The runner has already said why, so the driver adds nothing.
    'did not finish' => [null, []],
]);

it('reports a herd site whose securing failed as not served', function () {
    $runner = new RecordingRunner(['links --json' => herdLinks('blog-feature'), 'secure' => new FakeProcessResult('herd secure', 1)]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and($runner->warnings)->toBe(['Could not run [herd secure blog-feature]. The site may need to be secured manually.']);
});

it('waits for herd to list a site it links in the background', function () {
    Sleep::fake();

    $runner = new RecordingRunner(['links --json' => [herdLinks('blog'), herdLinks('blog'), herdLinks('blog', 'blog-feature')]]);

    expect((new HerdDriver)->serve(blogFeature(), false, $runner))->toBeTrue()
        ->and($runner->calls)->toHaveCount(4)
        ->and($runner->warnings)->toBe([]);

    Sleep::assertSleptTimes(2);
});

it('warns and leaves the site unsecured when herd never lists the site it linked', function () {
    Sleep::fake();

    $runner = new RecordingRunner(['links --json' => herdLinks('blog')]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and(array_count_values($runner->calls)['herd links --json in the main repository'])->toBe(5)
        ->and($runner->calls)->not->toContain('herd secure blog-feature in /sites/blog-feature')
        ->and($runner->warnings)->toHaveCount(1)
        ->and($runner->warnings[0])->toContain('does not list it')->toContain('[herd secure blog-feature]');

    Sleep::assertSleptTimes(4);
});

it('does not take a herd site whose name extends the worktree name for it', function (array $results) {
    expect((new HerdDriver)->isServed('blog-feature', '/sites/blog-feature', new RecordingRunner($results)))->toBeFalse();
})->with([
    'from the json list' => [['links --json' => herdLinks('blog-feature-login')]],
    'from the table' => [['links --json' => new FakeProcessResult('herd links --json', 1), 'links' => linksTable('blog-feature-login')]],
]);

it('reads the herd table when herd does not understand links --json', function (ProcessResult $json) {
    $runner = new RecordingRunner(['links --json' => $json, 'links' => linksTable('blog-feature')]);

    expect((new HerdDriver)->isServed('blog-feature', '/sites/blog-feature', $runner))->toBeTrue()
        ->and($runner->calls)->toBe([
            'herd links --json in the main repository',
            'herd links in the main repository',
        ]);
})->with([
    'an unknown option' => [new FakeProcessResult('herd links --json', 1, '', 'The "--json" option does not exist.')],
    'output that is not json' => [new FakeProcessResult('herd links --json', 0, '| Site |')],
]);

it('keeps reading the herd table for the rest of the wait once json failed', function () {
    Sleep::fake();

    $runner = new RecordingRunner([
        'links --json' => new FakeProcessResult('herd links --json', 1),
        'links' => [linksTable('blog'), linksTable('blog-feature')],
    ]);

    expect((new HerdDriver)->serve(blogFeature(), false, $runner))->toBeTrue()
        ->and($runner->calls)->toBe([
            'herd link blog-feature in /sites/blog-feature',
            'herd links --json in the main repository',
            'herd links in the main repository',
            'herd links in the main repository',
        ]);
});

it('cannot tell whether herd lists a site when its list fails', function (array $results) {
    $runner = new RecordingRunner($results);

    expect((new HerdDriver)->isServed('blog-feature', '/sites/blog-feature', $runner))->toBeNull()
        ->and((new HerdDriver)->serve(blogFeature(), false, $runner))->toBeTrue()
        ->and($runner->warnings)->toBe([]);
})->with([
    'json did not finish' => [['links --json' => null]],
    'both lists failed' => [['links' => new FakeProcessResult('herd links', 1)]],
]);

it('does not link again a herd site a resumed setup already served', function (string $provisioned) {
    // Linking again would let herd rewrite APP_URL in the worktree's .env and
    // run boost:update from its vendor directory.
    $base = sys_get_temp_dir().'/wt-sites-'.bin2hex(random_bytes(4));
    $worktree = Worktree::make('/sites/blog', 'feature', ['path' => $base, 'host' => ['template' => '{repo}-{branch}', 'tld' => 'test']]);
    mkdir($worktree->path(), 0777, true);

    $provisioned === '.env'
        ? file_put_contents($worktree->path().'/.env', "APP_URL=https://blog-feature.test\n")
        : mkdir($worktree->path().'/vendor');

    try {
        $runner = new RecordingRunner(['links --json' => herdLinks('blog-feature')]);

        expect((new HerdDriver)->serve($worktree, true, $runner))->toBeTrue()
            ->and($runner->calls)->toBe([
                'herd links --json in the main repository',
                "herd secure blog-feature in {$worktree->path()}",
            ]);

        $runner = new RecordingRunner(['links --json' => [herdLinks('blog'), herdLinks('blog-feature')]]);

        (new HerdDriver)->serve($worktree, false, $runner);

        expect($runner->calls)->toBe([
            'herd links --json in the main repository',
            "herd link blog-feature in {$worktree->path()}",
            'herd links --json in the main repository',
        ]);
    } finally {
        $provisioned === '.env' ? unlink($worktree->path().'/.env') : rmdir($worktree->path().'/vendor');
        rmdir($worktree->path());
        rmdir($base);
    }
})->with(['.env', 'vendor']);

it('unsecures then unlinks a herd site by name from the main repository', function (bool $secure, array $calls) {
    $runner = new RecordingRunner;

    (new HerdDriver)->unserve('blog-feature', existingWorktreeDirectory(), $secure, $runner);

    expect($runner->calls)->toBe($calls)
        ->and($runner->warnings)->toBe([]);
})->with([
    'secured' => [true, ['herd unsecure blog-feature in the main repository', 'herd unlink blog-feature in the main repository']],
    'over http' => [false, ['herd unlink blog-feature in the main repository']],
]);

it('still unlinks a herd site once its directory is gone', function () {
    $runner = new RecordingRunner;

    (new HerdDriver)->unserve('blog-feature', sys_get_temp_dir().'/wt-gone-'.bin2hex(random_bytes(4)), true, $runner);

    expect($runner->calls)->toBe([
        'herd unsecure blog-feature in the main repository',
        'herd unlink blog-feature in the main repository',
    ]);
});

it('warns when herd does not unlink a site', function () {
    $runner = new RecordingRunner(['unlink' => new FakeProcessResult('herd unlink', 1)]);

    (new HerdDriver)->unserve('blog-feature', existingWorktreeDirectory(), false, $runner);

    expect($runner->warnings)->toBe(['Could not run [herd unlink blog-feature]. The site may need to be removed manually.']);
});

it('gives the herd address with the scheme the site is served over', function () {
    expect((new HerdDriver)->url(blogFeature(), true))->toBe('https://blog-feature.test')
        ->and((new HerdDriver)->url(blogFeature(), false))->toBe('http://blog-feature.test');
});

it('finds a herd binary by its path or on PATH', function () {
    $binary = tempnam(sys_get_temp_dir(), 'wt-herd');

    try {
        expect((new HerdDriver($binary))->available())->toBeTrue()
            ->and((new HerdDriver($binary.'-missing'))->available())->toBeFalse()
            ->and((new HerdDriver('wt-missing-herd'))->available())->toBeFalse()
            ->and((new HerdDriver(PHP_OS_FAMILY === 'Windows' ? 'cmd' : 'sh'))->available())->toBeTrue();
    } finally {
        unlink($binary);
    }
});

it('links then secures a valet site by name', function (bool $secure, array $calls) {
    $runner = new RecordingRunner;

    expect((new ValetDriver)->serve(blogFeature(), $secure, $runner))->toBeTrue()
        ->and($runner->calls)->toBe($calls)
        ->and($runner->warnings)->toBe([]);
})->with([
    'secured' => [true, ['valet link blog-feature in /sites/blog-feature', 'valet secure blog-feature in /sites/blog-feature']],
    'over http' => [false, ['valet link blog-feature in /sites/blog-feature']],
]);

it('stops serving at the first valet command that fails', function () {
    $runner = new RecordingRunner(['link' => new FakeProcessResult('valet link', 1)]);

    expect((new ValetDriver('/usr/local/bin/valet'))->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and($runner->calls)->toBe(['/usr/local/bin/valet link blog-feature in /sites/blog-feature'])
        ->and($runner->warnings)->toBe(['Could not run [/usr/local/bin/valet link blog-feature]. The site may need to be served manually.']);
});

it('unsecures then unlinks a valet site by name from the main repository', function (bool $secure, array $calls) {
    $runner = new RecordingRunner;

    (new ValetDriver)->unserve('blog-feature', sys_get_temp_dir().'/wt-gone-'.bin2hex(random_bytes(4)), $secure, $runner);

    expect($runner->calls)->toBe($calls);
})->with([
    'secured' => [true, ['valet unsecure blog-feature in the main repository', 'valet unlink blog-feature in the main repository']],
    'over http' => [false, ['valet unlink blog-feature in the main repository']],
]);

it('reads whether valet lists a site from its links table', function (?ProcessResult $links, ?bool $expected) {
    $runner = new RecordingRunner(['links' => $links]);

    expect((new ValetDriver)->isServed('blog-feature', '/sites/blog-feature', $runner))->toBe($expected)
        ->and($runner->calls)->toBe(['valet links in the main repository']);
})->with([
    'listed' => [linksTable('blog', 'blog-feature'), true],
    'a name that extends it' => [linksTable('blog-feature-login'), false],
    'failed' => [new FakeProcessResult('valet links', 1), null],
    'did not finish' => [null, null],
]);

it('points a stuck valet command at valet trust', function () {
    expect((new ValetDriver)->timeoutHint())->toContain('valet trust')
        ->and((new ValetDriver)->url(blogFeature(), false))->toBe('http://blog-feature.test')
        ->and((new ValetDriver('wt-missing-valet'))->available())->toBeFalse();
});

it('runs the configured commands to serve a site, with the worktree tokens', function () {
    $runner = new RecordingRunner;

    $driver = new CommandDriver([
        'serve' => ['server', 'link', '{name}', '{path}', '--host={host}'],
        'secure' => ['server', 'secure', '{slug}', '{repo}', '{branch}', '{tld}'],
    ]);

    expect($driver->serve(blogFeature(), true, $runner))->toBeTrue()
        ->and($runner->calls)->toBe([
            'server link blog-feature /sites/blog-feature --host=blog-feature.test in /sites/blog-feature',
            'server secure blog_feature blog feature test in /sites/blog-feature',
        ]);

    $runner = new RecordingRunner;
    $driver->serve(blogFeature(), false, $runner);

    expect($runner->calls)->toBe(['server link blog-feature /sites/blog-feature --host=blog-feature.test in /sites/blog-feature']);
});

it('stops serving at the first configured command that fails', function () {
    $runner = new RecordingRunner(['link' => new FakeProcessResult('server link', 1)]);

    $driver = new CommandDriver(['serve' => ['server', 'link', '{name}'], 'secure' => ['server', 'secure', '{name}']]);

    expect($driver->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and($runner->calls)->toHaveCount(1)
        ->and($runner->warnings)->toBe(['Could not run [server link blog-feature]. The site may need to be served manually.']);
});

it('splits a configured command given as one string before expanding it', function () {
    $runner = new RecordingRunner;
    $worktree = Worktree::make('/my sites/blog', 'feature', ['host' => ['template' => '{repo}-{branch}', 'tld' => 'test']]);

    (new CommandDriver(['serve' => '  envkit  sites-link {path} ']))->serve($worktree, false, $runner);

    expect($runner->calls)->toBe(['envkit sites-link /my sites/blog-feature in /my sites/blog-feature']);
});

it('runs the configured commands to stop serving a site, with the tokens teardown knows', function () {
    $runner = new RecordingRunner;

    $driver = new CommandDriver([
        'unsecure' => ['server', 'unsecure', '{host}'],
        'unserve' => ['server', 'unlink', '{name}', '{path}', '{tld}', '{branch}'],
    ], 'localhost');

    $driver->unserve('blog-feature', 'C:\\sites\\blog-feature', true, $runner);

    expect($runner->calls)->toBe([
        'server unsecure blog-feature.localhost in the main repository',
        'server unlink blog-feature C:/sites/blog-feature localhost {branch} in the main repository',
    ]);

    $runner = new RecordingRunner;
    $driver->unserve('blog-feature', '/sites/blog-feature', false, $runner);

    expect($runner->calls)->toBe(['server unlink blog-feature /sites/blog-feature localhost {branch} in the main repository']);
});

it('says to remove a site by hand when no command stops serving it', function () {
    $runner = new RecordingRunner;

    (new CommandDriver(['serve' => ['envkit', 'sites-link', '{path}']]))->unserve('blog-feature', '/sites/blog-feature', false, $runner);

    expect($runner->calls)->toBe([])
        ->and($runner->warnings)->toHaveCount(1)
        ->and($runner->warnings[0])->toContain('[blog-feature]')->toContain('by hand');
});

it('checks a configured list for the site name, never for a name that extends it', function (string $output, bool $expected) {
    $runner = new RecordingRunner(['sites' => new FakeProcessResult('server sites', 0, $output)]);

    $driver = new CommandDriver(['serve' => ['server', 'link'], 'check' => ['server', 'sites', '{host}']]);

    expect($driver->isServed('blog-feature', '/sites/blog-feature', $runner))->toBe($expected)
        ->and($runner->calls)->toBe(['server sites blog-feature.test in the main repository']);
})->with([
    'a bare name' => ["blog\nblog-feature\n", true],
    'a host' => ['blog-feature.test -> /sites/blog-feature', true],
    'a url' => ['https://blog-feature.test', true],
    'a name that extends it' => ['blog-feature-login.test', false],
    'a name that extends it after a dot' => ['blog-feature.2.test', false],
    'a subdomain' => ['api.blog-feature.test', false],
    'nothing' => ['', false],
]);

it('cannot tell whether a site is served without a check that succeeds', function (array $commands, array $results) {
    $driver = new CommandDriver(['serve' => ['server', 'link'], ...$commands]);

    expect($driver->isServed('blog-feature', '/sites/blog-feature', new RecordingRunner($results)))->toBeNull();
})->with([
    'no check' => [[], []],
    'a failed check' => [['check' => ['server', 'sites']], ['sites' => new FakeProcessResult('server sites', 1, 'blog-feature')]],
]);

it('warns when the configured check does not list the site it served', function () {
    $runner = new RecordingRunner(['sites' => new FakeProcessResult('server sites', 0, 'blog')]);

    $driver = new CommandDriver(['serve' => ['server', 'link', '{name}'], 'check' => ['server', 'sites']]);

    expect($driver->serve(blogFeature(), false, $runner))->toBeFalse()
        ->and($runner->warnings)->toHaveCount(1)
        ->and($runner->warnings[0])->toContain('[server sites]')->toContain('[blog-feature]');
});

it('finds the configured serve command by its path or on PATH', function () {
    $binary = tempnam(sys_get_temp_dir(), 'wt-server');

    try {
        expect((new CommandDriver(['serve' => [$binary, 'link']]))->available())->toBeTrue()
            ->and((new CommandDriver(['serve' => $binary.' link']))->available())->toBeTrue()
            ->and((new CommandDriver(['serve' => ['wt-missing-server', 'link']]))->available())->toBeFalse()
            ->and((new CommandDriver)->available())->toBeFalse();
    } finally {
        unlink($binary);
    }
});

it('serves nothing with the none driver', function () {
    $runner = new RecordingRunner;
    $driver = new NoneDriver;

    $driver->unserve('blog-feature', '/sites/blog-feature', true, $runner);

    expect($driver->serve(blogFeature(), true, $runner))->toBeTrue()
        ->and($driver->url(blogFeature(), true))->toBeNull()
        ->and($driver->isServed('blog-feature', '/sites/blog-feature', $runner))->toBeNull()
        ->and($driver->available())->toBeTrue()
        ->and($runner->calls)->toBe([]);
});

it('serves a worktree under its site name, not its directory name', function () {
    // A site of its own lets "{branch}.{repo}" serve the worktree under the
    // main site's host, and keeps capitals and underscores out of every host,
    // while the directory keeps the worktree's name.
    $worktree = Worktree::make('/sites/blog', 'Feature_X', ['host' => ['template' => '{repo}-{branch}', 'site' => '{branch}.{repo}', 'tld' => 'test']]);

    $herd = new RecordingRunner(['links --json' => herdLinks('blog', 'feature-x.blog')]);
    $valet = new RecordingRunner;
    $command = new RecordingRunner(['check' => new FakeProcessResult('server check', 0, 'https://feature-x.blog.test')]);

    expect((new HerdDriver)->serve($worktree, true, $herd))->toBeTrue()
        ->and($herd->calls)->toBe([
            'herd link feature-x.blog in /sites/blog-Feature_X',
            'herd links --json in the main repository',
            'herd secure feature-x.blog in /sites/blog-Feature_X',
        ])
        ->and((new ValetDriver)->serve($worktree, false, $valet))->toBeTrue()
        ->and($valet->calls)->toBe(['valet link feature-x.blog in /sites/blog-Feature_X'])
        ->and((new CommandDriver([
            'serve' => ['server', 'link', '{name}', '{site}', '{host}', '{slug}'],
            'check' => ['server', 'check', '{name}', '{site}', '{host}'],
        ]))->serve($worktree, false, $command))->toBeTrue()
        ->and($command->calls)->toBe([
            'server link feature-x.blog feature-x.blog feature-x.blog.test blog_feature_x in /sites/blog-Feature_X',
            'server check feature-x.blog feature-x.blog feature-x.blog.test in the main repository',
        ])
        ->and((new HerdDriver)->url($worktree, true))->toBe('https://feature-x.blog.test');
});
