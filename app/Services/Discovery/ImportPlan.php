<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use Illuminate\Support\Collection;

/**
 * The full diff between a scanned tree and the registry.
 */
final readonly class ImportPlan
{
    /**
     * @param  Collection<int, ImportPlanEntry>  $entries
     */
    public function __construct(
        public TreeScan $scan,
        public Collection $entries,
    ) {}

    /**
     * @return Collection<int, ImportPlanEntry>
     */
    public function actionable(): Collection
    {
        return $this->entries->filter(
            static fn (ImportPlanEntry $entry): bool => $entry->action->isActionable()
        )->values();
    }

    /**
     * @return Collection<int, ImportPlanEntry>
     */
    public function recommended(): Collection
    {
        return $this->entries->filter(
            static fn (ImportPlanEntry $entry): bool => $entry->selectedByDefault()
        )->values();
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, ImportPlanEntry>
     */
    public function only(array $keys): Collection
    {
        $wanted = array_flip($keys);

        return $this->entries->filter(
            static fn (ImportPlanEntry $entry): bool => isset($wanted[$entry->key])
                && $entry->action->isActionable()
        )->values();
    }

    /**
     * Counts per action, for the summary line above the list.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (ImportAction::cases() as $action) {
            $counts[$action->value] = 0;
        }

        foreach ($this->entries as $entry) {
            $counts[$entry->action->value]++;
        }

        return $counts;
    }

    public function isEmpty(): bool
    {
        return $this->actionable()->isEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'root' => $this->scan->root,
            'shim_version' => $this->scan->shimVersion,
            'versions_on_disk' => $this->scan->versions,
            'wiki_versions' => $this->scan->wikiVersions,
            'warnings' => $this->scan->warnings,
            'scan_counts' => $this->scan->counts(),
            'counts' => $this->counts(),
            'actionable_count' => $this->actionable()->count(),
            'recommended_keys' => $this->recommended()->pluck('key')->all(),
            'entries' => $this->entries->map(
                static fn (ImportPlanEntry $entry): array => $entry->toArray()
            )->all(),
        ];
    }
}
