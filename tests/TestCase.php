<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\DeploymentDecision;
use App\Enums\RepositoryType;
use App\Models\MediaWikiVersion;
use App\Models\Permission;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Deployment\DecisionGate;
use App\Services\Salt\Contracts\SaltClient;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\AutoAnsweringDecisionGate;

abstract class TestCase extends BaseTestCase
{
    /**
     * Swap in the in-memory Salt client. Every test that touches the fleet uses
     * this; nothing in the suite may shell out to a real `salt` binary.
     */
    protected function fakeSalt(): FakeSaltClient
    {
        $fake = new FakeSaltClient;

        $this->app->instance(SaltClient::class, $fake);

        return $fake;
    }

    /**
     * A DecisionGate that answers blocking prompts the way an operator would,
     * and advances the test clock instead of sleeping.
     */
    protected function fakeDecisions(?DeploymentDecision $answer = null): AutoAnsweringDecisionGate
    {
        $gate = (new AutoAnsweringDecisionGate)->answerWith($answer);

        $this->app->instance(DecisionGate::class, $gate);

        return $gate;
    }

    /**
     * Create a user holding exactly the given permission names.
     *
     * @param  list<string>  $permissions
     */
    protected function userWithPermissions(array $permissions, bool $twoFactor = true): User
    {
        $user = User::factory()->create($twoFactor ? [
            'two_factor_secret' => encrypt('SECRET'),
            'two_factor_confirmed_at' => now(),
        ] : []);

        $role = Role::factory()->create();

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->getKey()]);
        }

        $user->roles()->attach($role);

        return $user->fresh();
    }

    /**
     * A user who can do everything, for tests about behaviour rather than access.
     */
    protected function admin(): User
    {
        return $this->userWithPermissions(array_keys(Permissions::all()));
    }

    /**
     * A core version, present on disk.
     */
    protected function version(string $version): MediaWikiVersion
    {
        return MediaWikiVersion::factory()->create(['version' => $version]);
    }

    /**
     * An extension checked out in one version, pinned to $pin.
     */
    protected function extension(string $name, MediaWikiVersion $version, string $pin = 'master'): RepositoryVersion
    {
        $repository = Repository::query()->firstOrCreate(
            ['type' => RepositoryType::Extension->value, 'name' => $name],
            ['git_url' => 'https://github.com/wikioasis/mediawiki-extensions-'.$name, 'default_branch' => 'master'],
        );

        return RepositoryVersion::factory()->of($repository, $version)->pinnedTo($pin)->create();
    }

    /**
     * MediaWiki core checked out as one version's tree.
     */
    protected function core(MediaWikiVersion $version, string $pin = 'REL1_45'): RepositoryVersion
    {
        $repository = Repository::query()->firstOrCreate(
            ['type' => RepositoryType::Core->value, 'name' => 'mediawiki'],
            ['git_url' => 'https://github.com/wikimedia/mediawiki', 'default_branch' => 'master'],
        );

        return RepositoryVersion::factory()->of($repository, $version)->pinnedTo($pin)->create();
    }
}
