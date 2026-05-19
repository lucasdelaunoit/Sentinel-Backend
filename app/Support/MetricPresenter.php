<?php

namespace App\Support;

use App\Metrics\BusFactorScale;
use App\Metrics\CriticalityScale;
use App\Metrics\FragilityScale;
use App\Metrics\Scale;
use App\Metrics\Severity;

/**
 * Shapes a raw metric into the standard card payload:
 *   { value, severity, change, hint, raw }
 *
 * - value:    display string ("Good", "3", "5/12", "75%")
 * - severity: ok | warning | critical — drives the traffic-light color
 * - change:   short delta line ("+5% in 7d"). Placeholder until snapshots land.
 * - hint:     optional context one-liner
 * - raw:      underlying numeric for tooltip / debug
 *
 * Scale-backed metrics (fragility, criticality, bus factor) route through
 * `fromScale()`. Everything else uses the generic helpers (count / ratio /
 * percentage / label) or the bare `make()` for one-off shapes.
 *
 * `$severity` accepts a `Severity` enum or its string value — string form is
 * tolerated so legacy array-builder code (Manager/Service layers shaping
 * `['severity' => $cond ? 'critical' : 'ok']`) can pass through unchanged.
 */
class MetricPresenter
{
    /** TODO: replace with real delta once project_metric_snapshots lands. */
    public const DEFAULT_CHANGE = 'Stable over 7d';

    public static function make(
        string $value,
        Severity|string $severity,
        int|float|null $raw = null,
        ?string $hint = null,
        ?string $change = null,
    ): array {
        return [
            'value'    => $value,
            'severity' => self::sev($severity),
            'change'   => $change ?? self::DEFAULT_CHANGE,
            'hint'     => $hint,
            'raw'      => $raw,
        ];
    }

    /** Render any metric whose label + severity come from a Scale enum. */
    public static function fromScale(Scale $tier, float|int $raw, ?string $hint = null): array
    {
        return self::make($tier->label(), $tier->severity(), (int) round($raw), $hint);
    }

    /** Project fragility (0-100, higher = worse). */
    public static function fragility(float|int $raw): array
    {
        return self::fromScale(FragilityScale::fromRaw($raw), $raw, 'Score: ' . (int) round($raw) . '/100');
    }

    /** User criticality (0-100). */
    public static function criticality(int $score): array
    {
        return self::fromScale(CriticalityScale::fromRaw($score), $score);
    }

    /** Bus factor (count, lower = worse). */
    public static function busFactor(int $bf): array
    {
        $hint = $bf > 0 ? "{$bf} key " . ($bf === 1 ? 'person' : 'people') : null;
        return self::fromScale(BusFactorScale::fromCount($bf), $bf, $hint);
    }

    /** Count card. Shows $zeroLabel when n=0, otherwise the number itself. */
    public static function count(int $n, Severity|string $severity = Severity::OK, ?string $zeroLabel = null, ?string $hint = null): array
    {
        $value = $n === 0 && $zeroLabel !== null ? $zeroLabel : (string) $n;
        return self::make($value, $severity, $n, $hint);
    }

    /** Ratio card — "a/b". */
    public static function ratio(int $a, int $b, Severity|string $severity = Severity::OK, ?string $hint = null): array
    {
        return self::make("{$a}/{$b}", $severity, $a, $hint);
    }

    /** Percentage card. Severity derived from thresholds (defaults: <50 crit, <75 warn). */
    public static function percentage(int $pct, int $warnAt = 75, int $critAt = 50, ?string $hint = null): array
    {
        $severity = match (true) {
            $pct < $critAt => Severity::CRITICAL,
            $pct < $warnAt => Severity::WARNING,
            default        => Severity::OK,
        };
        return self::make("{$pct}%", $severity, $pct, $hint);
    }

    /** Label-only card (no numeric raw). For categorical values like "Balanced". */
    public static function label(string $value, Severity|string $severity, ?string $hint = null): array
    {
        return self::make($value, $severity, null, $hint);
    }

    /** Normalize Severity|string to the string value that ships in JSON. */
    private static function sev(Severity|string $s): string
    {
        return $s instanceof Severity ? $s->value : $s;
    }
}
