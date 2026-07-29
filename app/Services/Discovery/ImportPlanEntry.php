<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\RepositoryType;

/**
 * One line on the import screen: what is on disk, what the registry says, and what
 * ticking the box would do.
 */
final readonly class ImportPlanEntry
{
    /**
     * @param  string  $key  stable across scan → review → apply; the tree path, or
     *                       "version:<ver>" for a core version row
     * @param  array<string, string|null>  $current  what the registry has now
     * @param  array<string, string|null>  $proposed  what applying would leave
     */
    public function __construct(
        public string $key,
        public ImportAction $action,
        public RepositoryType $type,
        public string $name,
        public ?string $version,
        public string $path,
        public string $summary,
        public array $current = [],
        public array $proposed = [],
        public ?ScannedCheckout $scanned = null,
        public ?int $repositoryId = null,
        public ?int $checkoutId = null,
        public ?string $note = null,
    ) {}

    public function selectedByDefault(): bool
    {
        return $this->action->recommended();
    }

    /**
     * Rendered for the API and for the artisan command's table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'action_description' => $this->action->description(),
            'badge_tone' => $this->action->badgeTone(),
            'actionable' => $this->action->isActionable(),
            'selected_by_default' => $this->selectedByDefault(),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'name' => $this->name,
            'manifest_name' => $this->scanned?->manifestName(),
            'version' => $this->version,
            'path' => $this->path,
            'summary' => $this->summary,
            'note' => $this->note,
            'current' => $this->current,
            'proposed' => $this->proposed,
            'repository_id' => $this->repositoryId,
            'checkout_id' => $this->checkoutId,
            'git_url' => $this->scanned?->gitUrl,
            'ref' => $this->scanned?->ref,
            'commit' => $this->scanned?->commit,
            'has_submodules' => (bool) $this->scanned?->hasSubmodules,
            'manifest' => $this->scanned?->manifest ?? [],
        ];
    }
}
