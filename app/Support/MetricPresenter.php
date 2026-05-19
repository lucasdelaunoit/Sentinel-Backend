<?php

namespace App\Support;

use App\Metrics\BusFactorScale;
use App\Metrics\CriticalityScale;
use App\Metrics\FragilityScale;
use App\Metrics\Scale;

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
 *
 * Domain-specific value/severity mapping lives in the App\Metrics\Scale enums.
 * Use `fromScale()` to render any metric that has a Scale; the generic
 * helpers (`count`, `ratio`, `percentage`, `label`) cover everything else.
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
     * <summary>
     *  Render a metric whose label + severity come from a Scale enum.
     *  Caller supplies the raw numeric (used for the tooltip and rounded raw output)
     *  and an optional precomposed hint string.
     * </summary>
     */
    public static function fromScale(Scale $tier, float|int $raw, ?string $hint = null): array
    {
        return self::make(
            value:    $tier->label(),
            severity: $tier->severity(),
            raw:      (int) round($raw),
            hint:     $hint,
        );
    }

    /** <summary>Project fragility (0-100, higher = worse).</summary> */
    public static function fragility(float|int $raw): array
    {
        return self::fromScale(FragilityScale::fromRaw($raw), $raw, "Score: " . (int) round($raw) . "/100");
    }

    /** <summary>User criticality (0-100).</summary> */
    public static function criticality(int $score): array
    {
        return self::fromScale(CriticalityScale::fromRaw($score), $score);
    }

    /** <summary>Bus factor (count, lower = worse).</summary> */
    public static function busFactor(int $bf): array
    {
        $hint = $bf > 0 ? "{$bf} key " . ($bf === 1 ? 'person' : 'people') : null;
        return self::fromScale(BusFactorScale::fromCount($bf), $bf, $hint);
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
