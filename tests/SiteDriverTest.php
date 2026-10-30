<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\FakeProcessResult;
use Mozex\Worktree\Contracts\SiteRunner;
use Mozex\Worktree\Drivers\HerdDriver;
use Mozex\Worktree\Drivers\NoneDriver;
use Mozex\Worktree\Worktree;

/**
 * Records each command a driver hands over, with where it would run, and
 * answers from canned results keyed by subcommand (null for a command that
 * did not run to its end). Anything not canned succeeds with no output.
 */
final class RecordingRunner implements SiteRunner
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  array<string, ProcessResult|null>  $results
     */
    public function __construct(protected array $results = []) {}

    public function run(array $command, ?string $path = null): ?ProcessResult
    {
        $this->calls[] = implode(' ', $command).' in '.($path ?? 'the main repository');

        $subcommand = $command[1] ?? '';

        return array_key_exists($subcommand, $this->results) ? $this->results[$subcommand] : new FakeProcessResult(implode(' ', $command));
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

it('links then secures a herd site and checks herd lists it', function () {
    $runner = new RecordingRunner(['links' => new FakeProcessResult('herd links', 0, '| blog-feature | https://blog-feature.test |')]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeTrue()
        ->and($runner->calls)->toBe([
            'herd link blog-feature in /sites/blog-feature',
            'herd secure in /sites/blog-feature',
            'herd links in /sites/blog-feature',
        ])
        ->and($runner->warnings)->toBe([]);
});

it('runs the configured herd binary, and only links a site served over http', function () {
    $runner = new RecordingRunner(['links' => new FakeProcessResult('', 0, 'blog-feature')]);

    (new HerdDriver('/opt/herd/bin/herd'))->serve(blogFeature(), false, $runner);

    expect($runner->calls)->toBe([
        '/opt/herd/bin/herd link blog-feature in /sites/blog-feature',
        '/opt/herd/bin/herd links in /sites/blog-feature',
    ]);
});

it('stops serving at the first herd command that fails', function (?ProcessResult $link) {
    $runner = new RecordingRunner(['link' => $link]);

    expect((new HerdDriver)->serve(blogFeature(), true, $runner))->toBeFalse()
        ->and($runner->calls)->toBe(['herd link blog-feature in /sites/blog-feature'])
        ->and($runner->warnings)->toBe(['Could not run [herd link blog-feature]. The site may need to be served manually.']);
})->with([
    'failed' => [new FakeProcessResult('herd link', 1)],
    'did not finish' => [null],
]);

it('warns when herd does not list the site it linked', function () {
    $runner = new RecordingRunner(['links' => new FakeProcessResult('herd links', 0, '| some-other-site |')]);

    expect((new HerdDriver)->serve(blogFeature(), false, $runner))->toBeFalse()
        ->and($runner->warnings)->toHaveCount(1)
        ->and($runner->warnings[0])->toContain('does not list it');
});

it('cannot tell whether herd lists a site when its list fails', function (?ProcessResult $links) {
    $runner = new RecordingRunner(['links' => $links]);

    expect((new HerdDriver)->isServed('blog-feature', '/sites/blog-feature', $runner))->toBeNull()
        ->and((new HerdDriver)->serve(blogFeature(), false, $runner))->toBeTrue()
        ->and($runner->warnings)->toBe([]);
})->with([
    'failed' => [new FakeProcessResult('herd links', 1)],
    'did not finish' => [null],
]);

it('unsecures then unlinks a herd site from its directory', function (bool $secure, array $calls) {
    $path = existingWorktreeDirectory();
    $runner = new RecordingRunner;

    (new HerdDriver)->unserve('blog-feature', $path, $secure, $runner);

    expect($runner->calls)->toBe(array_map(fn (string $call): string => "{$call} in {$path}", $calls));
})->with([
    'secured' => [true, ['herd unsecure', 'herd unlink blog-feature']],
    'over http' => [false, ['herd unlink blog-feature']],
]);

it('leaves a herd site alone once its directory is gone', function () {
    $runner = new RecordingRunner;

    (new HerdDriver)->unserve('blog-feature', sys_get_temp_dir().'/wt-gone-'.bin2hex(random_bytes(4)), true, $runner);

    expect($runner->calls)->toBe([]);
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
