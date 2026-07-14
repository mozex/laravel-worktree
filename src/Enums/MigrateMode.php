<?php

declare(strict_types=1);

namespace Mozex\Worktree\Enums;

enum MigrateMode: string
{
    case Fresh = 'fresh';
    case Migrate = 'migrate';
    case None = 'none';

    public function command(): ?string
    {
        return match ($this) {
            self::Fresh => 'migrate:fresh',
            self::Migrate => 'migrate',
            self::None => null,
        };
    }
}
