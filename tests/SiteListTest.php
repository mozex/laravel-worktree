<?php

declare(strict_types=1);

use Mozex\Worktree\Support\SiteList;

it('finds a site name as a cell of its own in a table', function (string $table, bool $expected) {
    expect(SiteList::hasCell($table, 'blog-feature'))->toBe($expected);
})->with([
    'a symfony table' => ["+------+\n| Site | SSL |\n| blog-feature |  X  | https://blog-feature.test |", true],
    'a box-drawn table' => ['│ blog-feature │ https://blog-feature.test │', true],
    'a table spaced by tabs' => ["blog\tblog.test\nblog-feature\tblog-feature.test", true],
    'a go table spaced by runs of spaces' => ["NAME          DOMAIN\nblog-feature  blog-feature.test     8.4", true],
    'a row that is not valid utf-8' => ["| blog-feature |  X  | C:\\code\\Jos\xE9\\sites\\blog-feature |", true],
    'in another case' => ['| Blog-Feature |', true],
    'a name that extends it' => ['| blog-feature-login | https://blog-feature-login.test |', false],
    'only inside a url or path' => ['| other | https://blog-feature.test | /sites/blog-feature |', false],
    'cut short' => ['| blog-feat... |', false],
    'nothing' => ['', false],
]);

it('finds a long name a table cut short to fit its column', function (string $table, bool $expected) {
    expect(SiteList::hasShortenedCell($table, 'customer-portal-api-v2'))->toBe($expected);
})->with([
    'whole' => ['customer-portal-api-v2  customer-portal-api-v2.test', true],
    'cut short' => ['customer-portal-api...  customer-portal-api-v2.test', true],
    'cut short, another name' => ['customer-portal-web...  customer-portal-web-v2.test', false],
    'cut too short to tell' => ['customer...  other.test', false],
    'a name that extends it' => ['customer-portal-api-v2-old  other.test', false],
]);

it('finds a site name mentioned alone, in a host, a url, or a path', function (string $output, bool $expected) {
    expect(SiteList::mentions($output, 'blog-feature', 'test'))->toBe($expected);
})->with([
    'alone' => ['blog-feature', true],
    'a host' => ['blog-feature.test', true],
    'a url' => ['https://blog-feature.test/', true],
    'a host and port' => ['blog-feature.test:8080', true],
    'a path' => ['/sites/blog-feature', true],
    'in another case' => ['BLOG-FEATURE.test', true],
    'a name that extends it' => ['blog-feature-login.test', false],
    'a name that extends it after a dot' => ['blog-feature.2.test', false],
    'a host under another tld' => ['blog-feature.testing', false],
    'a name it ends' => ['old-blog-feature.test', false],
    'a subdomain' => ['api.blog-feature.test', false],
    'with an underscore after' => ['blog-feature_2', false],
]);
