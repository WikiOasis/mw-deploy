<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\User;

/**
 * The installed apps, resolved once.
 *
 * Everything that needs to know what this console contains asks here: the API
 * route table (one middleware-wrapped group per app), the launcher, the access
 * admin screen and the bootstrap payload. Nothing hard-codes the list.
 */
final class AppRegistry
{
    /** @var array<string, ConsoleApp>|null id => app, memoised */
    private ?array $resolved = null;

    /**
     * @param  list<class-string<ConsoleApp>>|null  $classes  overrides config, for tests
     */
    public function __construct(private readonly ?array $classes = null) {}

    /**
     * Every app this console ships with, enabled or not.
     *
     * @return array<string, ConsoleApp>
     */
    public function installed(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $apps = [];

        foreach ($this->classes ?? (array) config('console.apps', []) as $class) {
            $app = app($class);

            $apps[$app->id()] = $app;
        }

        return $this->resolved = $apps;
    }

    /**
     * The apps this install has switched on.
     *
     * @return array<string, ConsoleApp>
     */
    public function enabled(): array
    {
        return array_filter($this->installed(), fn (ConsoleApp $app): bool => $app->isEnabled());
    }

    public function find(string $id): ?ConsoleApp
    {
        return $this->installed()[$id] ?? null;
    }

    /**
     * The apps this account may open, in declaration order.
     *
     * @return array<string, ConsoleApp>
     */
    public function availableTo(User $user): array
    {
        return array_filter($this->enabled(), fn (ConsoleApp $app): bool => $app->accessibleBy($user));
    }

    /**
     * The launcher payload: every enabled app, each flagged with whether this
     * account may open it. Apps the account cannot open are still listed — a
     * locked tile is how someone finds out what to ask for.
     *
     * @return list<array<string, mixed>>
     */
    public function launcherFor(?User $user): array
    {
        return array_values(array_map(
            fn (ConsoleApp $app): array => $app->toArray($user),
            $this->enabled(),
        ));
    }
}
