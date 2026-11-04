<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

final readonly class Page
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public string $sort,
    ) {}

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function map(callable $fn): self
    {
        return new self(array_map($fn, $this->items), $this->total, $this->page, $this->perPage, $this->sort);
    }

    /** @return array{page:int, per_page:int, total:int, pages:int, sort:string} */
    public function meta(): array
    {
        return ['page' => $this->page, 'per_page' => $this->perPage, 'total' => $this->total, 'pages' => $this->pages(), 'sort' => $this->sort];
    }
}
