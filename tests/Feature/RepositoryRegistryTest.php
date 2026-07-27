<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RepositoryType;
use App\Enums\StepName;
use App\Models\Repository;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Section 4.5: registering a repository is a trust decision distinct from
 * deploying, and it clones onto staging in the same step.
 */
final class RepositoryRegistryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mwdeploy.targets.staging' => 'staging']);

        $this->salt = $this->fakeSalt();
    }

    #[Test]
    public function browsing_is_open_to_anyone_with_an_account(): void
    {
        Repository::factory()->create(['name' => 'Echo']);

        $this->actingAs($this->userWithPermissions([]))
            ->get(route('repositories.index'))
            ->assertOk()
            ->assertSee('Echo');
    }

    #[Test]
    public function a_deployer_without_the_manage_permission_cannot_register_one(): void
    {
        $user = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($user)->get(route('repositories.create'))->assertForbidden();

        $this->actingAs($user)->post(route('repositories.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, Repository::query()->count());
    }

    #[Test]
    public function registering_clones_onto_staging_and_derives_the_path(): void
    {
        $user = $this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]);

        $this->actingAs($user)
            ->post(route('repositories.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $repository = Repository::query()->firstOrFail();

        $this->assertSame('versions/1.45/extensions/Echo', $repository->path);
        $this->assertSame($user->getKey(), $repository->created_by);
        $this->assertNotNull($repository->registered_at);

        $this->salt->assertRan(StepName::RepoRegister, 'staging');

        $command = $this->salt->callsFor(StepName::RepoRegister)[0]->command->toString();
        $this->assertStringContainsString('/srv/mediawiki-staging/versions/1.45/extensions/Echo', $command);
    }

    #[Test]
    public function a_failed_clone_leaves_no_registry_entry_behind(): void
    {
        $this->salt->alwaysRespondTo(StepName::RepoRegister, false);

        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), $this->payload())
            ->assertSessionHasErrors('git_url');

        // A broken entry would then fail every deployment wizard referencing it.
        $this->assertSame(0, Repository::query()->count());
    }

    #[Test]
    public function a_new_core_version_is_registered_as_a_version_directory(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), [
                'name' => 'mediawiki',
                'type' => RepositoryType::Core->value,
                'git_url' => 'https://github.com/wikimedia/mediawiki',
                'default_branch' => 'REL1_46',
                'core_version' => '1.46',
            ])
            ->assertSessionHasNoErrors();

        $repository = Repository::query()->firstOrFail();

        $this->assertSame('versions/1.46', $repository->path);

        $command = $this->salt->callsFor(StepName::RepoRegister)[0]->command->toString();

        $this->assertStringContainsString("'--kind' 'core-version'", $command);
        $this->assertStringContainsString("'--version' '1.46'", $command);
    }

    #[Test]
    public function a_core_repository_without_a_version_is_rejected(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), [
                'name' => 'mediawiki',
                'type' => RepositoryType::Core->value,
                'git_url' => 'https://github.com/wikimedia/mediawiki',
                'default_branch' => 'master',
            ])
            ->assertSessionHasErrors('core_version');
    }

    #[Test]
    public function it_rejects_a_git_url_that_is_not_https_or_ssh(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), [...$this->payload(), 'git_url' => 'file:///etc/passwd'])
            ->assertSessionHasErrors('git_url');

        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function it_rejects_a_name_that_would_escape_the_staging_tree(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), [...$this->payload(), 'name' => '../../etc/cron.d'])
            ->assertSessionHasErrors('name');

        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function it_rejects_a_duplicate_registration_for_the_same_version(): void
    {
        Repository::factory()->create(['name' => 'Echo', 'core_version' => '1.45']);

        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), $this->payload())
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function the_same_extension_can_be_registered_for_a_second_core_version(): void
    {
        Repository::factory()->create(['name' => 'Echo', 'core_version' => '1.45']);

        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->post(route('repositories.store'), [...$this->payload(), 'core_version' => '1.46'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Repository::query()->where('name', 'Echo')->count());
        $this->assertSame(
            'versions/1.46/extensions/Echo',
            Repository::query()->where('core_version', '1.46')->firstOrFail()->path,
        );
    }

    #[Test]
    public function deleting_deactivates_rather_than_destroys_so_history_still_resolves(): void
    {
        $repository = Repository::factory()->create();

        $this->actingAs($this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]))
            ->delete(route('repositories.destroy', $repository))
            ->assertRedirect(route('repositories.index'));

        $this->assertDatabaseHas('repositories', ['id' => $repository->getKey(), 'active' => false]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'name' => 'Echo',
            'type' => RepositoryType::Extension->value,
            'git_url' => 'https://github.com/wikioasis/mediawiki-extensions-Echo',
            'default_branch' => 'master',
            'core_version' => '1.45',
        ];
    }
}
