<?php

namespace App\DTO\Stats;

final readonly class KnowledgeCoverageBreakdown
{
    /**
     * @param array<int, array{category_id:int,category_name:string,coverage_pct:int,safe:int,siloed:int,uncovered:int,siloed_skills:array<int,string>,uncovered_skills:array<int,string>}> $categories One entry per skill category with requirements across active projects — a radar axis
     * @param string|null $mostFragile Name of the lowest-coverage category, or null
     */
    public function __construct(
        public array $categories,
        public ?string $mostFragile,
    ) {}

    public function toArray(): array
    {
        return [
            'categories' => $this->categories,
            'most_fragile' => $this->mostFragile,
        ];
    }
}
