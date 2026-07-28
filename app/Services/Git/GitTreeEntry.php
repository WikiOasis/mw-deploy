<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitTreeEntry
{
    public function __construct(
        public string $name,
        public string $type, // blob|tree|commit (submodule)
        public string $mode,
        public ?int $size = null,
    ) {}

    public function isDirectory(): bool
    {
        return $this->type === 'tree';
    }
}
