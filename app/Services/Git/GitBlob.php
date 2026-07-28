<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitBlob
{
    public function __construct(
        public string $content,
        public int $size,
        public bool $truncated,
        public bool $binary,
    ) {}
}
