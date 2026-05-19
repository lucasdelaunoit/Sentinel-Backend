<?php

namespace App\Support;

use App\Services\RiskCalculationService;

/**
 * Metric presenter. Shapes a raw domain metric into the standard
 * display-ready payload consumed by stats endpoints, list rows,
 * detail views, exports, etc.
 *
 * Shape: { value, severity, change, hint, raw }
 *  - value:    human label ("Good", "On Track", "3 fragile")
 *  - severity: ok | warning | critical (drives traffic-light color)
 *  - change:   short delta string ("+5% in 7d"). Hardcoded for now — TODO real snapshots
 *  - hint:     optional one-liner context
 *  - raw:      underlying numeric for tooltip / debug
 */
class MetricPresenter
{
    // TODO(change): real change requires snapshot history. Hardcode until project_metric_snapshots lands.
    public const PLACEHOLDER_CHANGE = 'Stable over 7d';

    public static function make(string $value, string $severity, int|float|null $raw = null, ?string $change = null, ?string $hint = null): array
    {
        return [
            'value'    => $value,
            'severity' => $severity,
            'change'   => $change ?? self::PLACEHOLDER_CHANGE,
            'hint'     => $hint,
            'raw'      => $raw,
        ];
    }

    /**
     * <summary>Card for a project fragility raw score.</summary>
     */
    public static function fragility(float|int $raw): array
    {
        $tier  = RiskCalculationService::fragilityTier($raw);
        $value = match ($tier) {
            'solid'     => 'Excellent',
            'stable'    => 'Good',
            'stretched' => 'Watch',
            'fragile'   => 'Fragile',
            'critical'  => 'Critical',
        };

        return self::make(
            value:    $value,
            severity: RiskCalculationService::fragilitySeverity($raw),
            raw:      (int) round($raw),
            hint:     "Score: " . (int) round($raw) . "/100",
        );
    }

    /**
     * <summary>Card for a project trajectory raw score.</summary>
     */
    public static function trajectory(float|int $raw): array
    {
        $tier  = RiskCalculationService::trajectoryTier($raw);
        $value = match ($tier) {
            'off_course' => 'Off Track',
            'drifting'   => 'Slipping',
            'wobbling'   => 'Wobbling',
            'on_track'   => 'On Track',
            'cruising'   => 'Cruising',
        };

        return self::make(
            value:    $value,
            severity: RiskCalculationService::trajectorySeverity($raw),
            raw:      (int) round($raw),
            hint:     "Score: " . (int) round($raw) . "/100",
        );
    }

    /**
     * <summary>Card for a user criticality score (0-100).</summary>
     */
    public static function criticality(int $score): array
    {
        $value = match (true) {
            $score >= 60 => 'Critical',
            $score >= 30 => 'Notable',
            default      => 'Low Risk',
        };

        return self::make(
            value:    $value,
            severity: RiskCalculationService::criticalitySeverity($score),
            raw:      $score,
        );
    }

    /**
     * <summary>Card for a bus factor integer (lower = worse).</summary>
     */
    public static function busFactor(int $bf): array
    {
        $severity = match (true) {
            $bf <= 1 => 'critical',
            $bf <= 2 => 'warning',
            default  => 'ok',
        };
        $value = match (true) {
            $bf === 0 => 'No coverage',
            $bf === 1 => 'Single point',
            $bf <= 2  => 'Thin',
            $bf <= 4  => 'Adequate',
            default   => 'Resilient',
        };

        return self::make(
            value:    $value,
            severity: $severity,
            raw:      $bf,
            hint:     $bf > 0 ? "{$bf} key " . ($bf === 1 ? 'person' : 'people') : null,
        );
    }

    /**
     * <summary>
     *  Generic count card. value is just the number ("3"), or $zeroLabel when n=0.
     *  Title of card on the frontend conveys "what" — value stays compact.
     *  $hint carries optional context line.
     * </summary>
     */
    public static function count(int $n, string $severity = 'ok', ?string $zeroLabel = null, ?string $hint = null): array
    {
        $value = $n === 0 && $zeroLabel !== null ? $zeroLabel : (string) $n;
        return self::make(
            value:    $value,
            severity: $severity,
            raw:      $n,
            hint:     $hint,
        );
    }

    /**
     * <summary>
     *  Ratio card. value = "a/b". For team availability, present/total etc.
     * </summary>
     */
    public static function ratio(int $a, int $b, string $severity = 'ok', ?string $hint = null): array
    {
        return self::make(
            value:    "{$a}/{$b}",
            severity: $severity,
            raw:      $a,
            hint:     $hint,
        );
    }

    /**
     * <summary>Percentage card. value = "{pct}%". Severity from low/mid/high thresholds.</summary>
     */
    public static function percentage(int $pct, int $warnAt = 75, int $critAt = 50, ?string $hint = null): array
    {
        $severity = match (true) {
            $pct < $critAt => 'critical',
            $pct < $warnAt => 'warning',
            default        => 'ok',
        };
        return self::make(
            value:    "{$pct}%",
            severity: $severity,
            raw:      $pct,
            hint:     $hint,
        );
    }

    /**
     * <summary>Label-only card (no numeric raw). For categorical values like "Balanced".</summary>
     */
    public static function label(string $value, string $severity, ?string $hint = null): array
    {
        return self::make(value: $value, severity: $severity, raw: null, hint: $hint);
    }
}
