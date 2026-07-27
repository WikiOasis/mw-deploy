<?php

declare(strict_types=1);

namespace App\Services\Git;

final readonly class GitRef
{
    public function __construct(
        public string $value,
        public ?string $subject = null,
        public ?string $author = null,
        public ?string $date = null,
        public bool $isDefault = false,
    ) {}

    public function short(): string
    {
        return preg_match('/^[0-9a-f]{40}$/i', $this->value) === 1
            ? substr($this->value, 0, 10)
            : $this->value;
    }

    public function describe(): string
    {
        $parts = [$this->short()];

        if ($this->subject !== null && $this->subject !== '') {
            $parts[] = $this->subject;
        }

        if ($this->author !== null && $this->author !== '') {
            $parts[] = '— '.$this->author;
        }

        return implode(' ', $parts);
    }
}
