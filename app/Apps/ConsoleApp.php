<?php

declare(strict_types=1);

namespace App\Apps;

use App\Models\User;

/**
 * One app in the console.
 *
 * An app is a submodule with a boundary around it: its own permission
 * vocabulary, its own API routes, its own screens under its own path prefix, and
 * an access grant that decides whether an account sees it on the launcher at
 * all. The console shell knows nothing about what any particular app does.
 *
 * Implementations are listed in config/console.php and resolved through the
 * container, so an app may take dependencies in its constructor.
 */
interface ConsoleApp
{
    /**
     * Stable machine id: the URL prefix, the access permission's middle segment
     * and the key the client-side manifest is registered under. Never renamed
     * once grants exist against it.
     */
    public function id(): string;

    /** Human name, as shown on the launcher tile and in the chrome. */
    public function name(): string;

    /** One line for the launcher tile. */
    public function summary(): string;

    /** Short tile glyph — two or three characters, not an icon font. */
    public function icon(): string;

    /** Client-side entry path, e.g. `/deployments`. */
    public function path(): string;

    /**
     * The permission that grants read access to this app on its own, without
     * granting anything inside it.
     */
    public function accessPermission(): string;

    /**
     * This app's whole permission vocabulary, name => description, including
     * its access permission.
     *
     * @return array<string, string>
     */
    public function permissions(): array;

    /** Absolute path to the app's API route file, or null if it has none. */
    public function routeFile(): ?string;

    /** False when this install has switched the app off. */
    public function isEnabled(): bool;

    /** Whether this account may open the app. */
    public function accessibleBy(User $user): bool;

    /**
     * The launcher/bootstrap representation, from one account's point of view.
     *
     * @return array<string, mixed>
     */
    public function toArray(?User $user = null): array;
}
