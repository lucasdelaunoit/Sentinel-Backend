<?php

namespace App\Metrics;

/**
 * Bus factor tier. Driven by a raw count (lower = worse), not a 0-100 score.
 *   0 no_coverage · 1 single_point · 2 thin · 3-4 adequate · 5+ resilient.
 */
enum BusFactorScale: string implements Scale
{
    case NoCoverage  = 'no_coverage';
    case SinglePoint = 'single_point';
    case Thin        = 'thin';
    case Adequate    = 'adequate';
    case Resilient   = 'resilient';

    public static function fromCount(int $bf): self
    {
        return match (true) {
            $bf === 0 => self::NoCoverage,
            $bf === 1 => self::SinglePoint,
            $bf <= 2  => self::Thin,
            $bf <= 4  => self::Adequate,
            default   => self::Resilient,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NoCoverage  => 'No coverage',
            self::SinglePoint => 'Single point',
            self::Thin        => 'Thin',
            self::Adequate    => 'Adequate',
            self::Resilient   => 'Resilient',
        };
    }

    public function severity(): Severity
    {
        return match ($this) {
            self::NoCoverage, self::SinglePoint => Severity::CRITICAL,
            self::Thin                          => Severity::WARNING,
            self::Adequate, self::Resilient     => Severity::OK,
        };
    }
}
