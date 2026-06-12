<?php

namespace App\DTO;

use Illuminate\Support\Carbon;

/**
 * Typed DTO returned by CalculationRunManager::getSyncStatus — wire shape for the
 * GET .../sync-status endpoints (project / user / dashboard sync cards).
 */
final readonly class CalculationSyncStatus
{
    public function __construct(
        public string $state, // idle | queued | running | failed
        public ?Carbon $lastCalculatedAt,
        public ?int $processedItems,
        public ?int $totalItems,
        public ?string $error,
    ) {}

    public function toArray(): array
    {
        $progress = null;
        if ($this->processedItems !== null && $this->totalItems !== null && $this->totalItems > 0) {
            $progress = [
                'processed' => $this->processedItems,
                'total' => $this->totalItems,
                'percent' => (int) round(($this->processedItems / $this->totalItems) * 100),
            ];
        }

        return [
            'state' => $this->state,
            'last_calculated_at' => $this->lastCalculatedAt?->toIso8601String(),
            'progress' => $progress,
            'error' => $this->error,
        ];
    }
}
