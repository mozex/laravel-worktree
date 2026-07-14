<?php

declare(strict_types=1);

namespace Mozex\Worktree\Enums;

enum HerdMode: string
{
    case Secure = 'secure';
    case Link = 'link';
    case None = 'none';

    public function scheme(): string
    {
        return match ($this) {
            self::Secure => 'https',
            self::Link, self::None => 'http',
        };
    }

    public function enabled(): bool
    {
        return $this !== self::None;
    }
}
