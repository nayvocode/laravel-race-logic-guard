<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Support;

/**
 * Severity levels for RaceGuard findings, ordered from least to most severe.
 */
final class Severity
{
    public const LOW = 'low';

    public const MEDIUM = 'medium';

    public const HIGH = 'high';

    public const CRITICAL = 'critical';

    /**
     * Weight used to compare severities. Higher is more severe.
     *
     * @var array<string, int>
     */
    private const WEIGHTS = [
        self::LOW => 1,
        self::MEDIUM => 2,
        self::HIGH => 3,
        self::CRITICAL => 4,
    ];

    /**
     * All known severities, ordered from most to least severe.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];
    }

    public static function isValid(string $severity): bool
    {
        return isset(self::WEIGHTS[$severity]);
    }

    public static function weight(string $severity): int
    {
        return self::WEIGHTS[$severity] ?? 0;
    }

    /**
     * Is $severity at least as severe as $threshold?
     */
    public static function atLeast(string $severity, string $threshold): bool
    {
        return self::weight($severity) >= self::weight($threshold);
    }

    /**
     * A stable label suitable for headings, e.g. "HIGH".
     */
    public static function label(string $severity): string
    {
        return strtoupper($severity);
    }
}
