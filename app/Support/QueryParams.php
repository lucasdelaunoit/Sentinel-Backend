<?php

namespace App\Support;

use Illuminate\Http\Request;

class QueryParams
{
    private function __construct(
        private readonly array $normalized,
        private readonly array $rawQuery,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $normalized = $request->query();
        $filters    = $request->input('filter', []);

        if ($request->filled('search') && empty($filters['search'])) {
            $normalized['filter']['search'] = $request->input('search');
            unset($normalized['search']);
        }

        return new self($normalized, $request->query());
    }

    /**
     * Synthetic Request for Spatie QueryBuilder — no HTTP coupling leaks into Service.
     */
    public function toRequest(): Request
    {
        return Request::create('/', 'GET', $this->normalized);
    }

    public function perPage(int $default = 20): int
    {
        return (int) ($this->normalized['per_page'] ?? $default);
    }

    public function rawQuery(): array
    {
        return $this->rawQuery;
    }
}
