<?php

declare(strict_types=1);

namespace Mozex\Worktree\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    use WithWorkbench;
}
