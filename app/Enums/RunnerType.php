<?php

namespace App\Enums;

enum RunnerType: string
{
    case Cypress = 'cypress';
    case Playwright = 'playwright';

    public function label(): string
    {
        return match ($this) {
            self::Cypress => 'Cypress',
            self::Playwright => 'Playwright',
        };
    }
}
