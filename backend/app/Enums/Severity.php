<?php

namespace App\Enums;

enum Severity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 100,
            self::High => 75,
            self::Medium => 50,
            self::Low => 25,
            self::Info => 5,
        };
    }
}
