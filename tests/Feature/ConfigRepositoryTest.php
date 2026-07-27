<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\RepositoryType;
use App\Enums\StepName;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\Repository;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Registering the config repository in one field.
 *
 * mw-config is the repository every farm has exactly one of, always in the same
 * place, never versioned — so the generic form's name/type/version/pin questions
 * all have one possible answer. What this flow has to get right is the difference
 * between a farm that already has the checkout on disk (adopt it) and one that does
 * not (clone it), without asking the operator which they are.
 */
final class ConfigRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->salt = $this->fakeSalt();
    }

    #[Test]
    public function it_adopts_a_config_checkout_that_is_already_on_disk(): void
    {
        $this->scanFinds([
            'kind' => 'config',
            'name' => 'config',
            'path' => 'config',
            'version' => null,
            'is_git' => true,
            'git' => [
                'url' => 'https://github.com/wikioasis/mw-config.git',
                'ref_type' => 'branch',
                'ref' => 'main',
                'commit' => str_repeat('c', 40),
                'branch' => 'main',
                'default_branch' => 'main',
                'upstream' => 'origin/main',
                'has_submodules' => false,
            ],
        ]);

        $response = $this->actingAs($this->admin())->postJson(route('api.repositories.config.store'), [
            'git_url' => 'https://github.com/wikioasis/mw-config.git',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('adopted', true);
        $response->assertJsonPath('deployment_id', null);

        $repository = Repository::query()->ofType(RepositoryType::Config)->firstOrFail();

        // Named after the remote, because that is what an operator would have typed.
        $this->assertSame('mw-config', $repository->name);
        $this->assertSame('main', $repository->default_branch);
        $this->assertNotNull($repository->discovered_at);

        $checkout = $repository->versions()->firstOrFail();

        $this->assertSame('config', $checkout->path);
        $this->assertNull($checkout->mediawiki_version_id, 'Config is never versioned.');
        $this->assertTrue($checkout->isPresent());
        $this->assertSame('main', $checkout->tracked_ref_value);

        // Nothing was cloned over the top of a live config checkout, and no
        // deployment was invented to describe work that did not happen.
        $this->salt->assertNeverRan(StepName::RepoRegister);
        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_registers_and_clones_when_the_tree_has_no_config_checkout(): void
    {
        $this->scanFinds(null);

        $response = $this->actingAs($this->admin())->postJson(route('api.repositories.config.store'), [
            'git_url' => 'https://github.com/wikioasis/mw-config.git',
            'default_branch' => 'master',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('adopted', false);

        $deployment = Deployment::query()->latest('id')->firstOrFail();

        $this->assertSame($response->json('deployment_id'), $deployment->getKey());
        // Registering lands on staging only; rolling it out is a separate,
        // deliberate deployment.
        $this->assertTrue($deployment->opts()->stagingOnly);
        $this->assertSame(1, $deployment->repoRefs()->count());

        Queue::assertPushed(RunDeployment::class);
    }

    #[Test]
    public function an_unreachable_remote_is_refused_at_the_form(): void
    {
        $this->scanFinds(null);
        $this->salt->respondTo(StepName::GitRemoteCheck, false);

        $this->actingAs($this->admin())
            ->postJson(route('api.repositories.config.store'), [
                'git_url' => 'https://github.com/wikioasis/typo.git',
            ])
            ->assertJsonValidationErrors('git_url');

        // A registry row for an unreachable URL would break every wizard that
        // mentions it, so nothing is written.
        $this->assertSame(0, Repository::query()->count());
    }

    #[Test]
    public function it_refuses_a_remote_that_is_not_https_or_ssh(): void
    {
        $this->scanFinds(null);

        $this->actingAs($this->admin())
            ->postJson(route('api.repositories.config.store'), ['git_url' => '/srv/local/mw-config'])
            ->assertJsonValidationErrors('git_url');
    }

    #[Test]
    public function the_screen_reports_what_is_on_disk_and_what_is_registered(): void
    {
        $this->scanFinds([
            'kind' => 'config',
            'name' => 'config',
            'path' => 'config',
            'version' => null,
            'is_git' => true,
            'git' => [
                'url' => 'https://github.com/wikioasis/mw-config.git',
                'ref_type' => 'branch',
                'ref' => 'main',
                'commit' => str_repeat('c', 40),
                'branch' => 'main',
                'default_branch' => 'main',
                'upstream' => 'origin/main',
                'has_submodules' => false,
            ],
        ]);

        $this->actingAs($this->admin())
            ->getJson(route('api.repositories.config'))
            ->assertOk()
            ->assertJsonPath('repository', null)
            ->assertJsonPath('on_disk.path', 'config')
            ->assertJsonPath('on_disk.importable', true)
            ->assertJsonPath('on_disk.git_url', 'https://github.com/wikioasis/mw-config.git')
            ->assertJsonPath('config_dir', (string) config('mwdeploy.paths.config_dir'));
    }

    #[Test]
    public function a_tree_the_portal_cannot_read_does_not_block_registering(): void
    {
        // A scan failure here means the portal cannot see the tree. Falling through
        // to register-and-clone is the right guess: the shim refuses to clone into a
        // non-empty directory, so the wrong guess fails safely rather than
        // overwriting a config repository.
        $this->salt->alwaysRespondTo(StepName::TreeScan, false);

        $this->actingAs($this->admin())
            ->getJson(route('api.repositories.config'))
            ->assertOk()
            ->assertJsonPath('on_disk', null)
            ->assertJsonStructure(['scan_error']);

        $this->actingAs($this->admin())
            ->postJson(route('api.repositories.config.store'), [
                'git_url' => 'https://github.com/wikioasis/mw-config.git',
            ])
            ->assertCreated()
            ->assertJsonPath('adopted', false);
    }

    #[Test]
    public function registering_config_needs_the_repository_manage_permission(): void
    {
        $this->scanFinds(null);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CONFIG]))
            ->postJson(route('api.repositories.config.store'), [
                'git_url' => 'https://github.com/wikioasis/mw-config.git',
            ])
            ->assertForbidden();

        $this->assertSame(0, Repository::query()->count());
    }

    #[Test]
    public function the_config_checkout_path_follows_the_configured_directory(): void
    {
        config(['mwdeploy.paths.config_dir' => 'wikiconfig']);

        $this->scanFinds(null);

        $this->actingAs($this->admin())
            ->postJson(route('api.repositories.config.store'), [
                'git_url' => 'https://github.com/wikioasis/mw-config.git',
            ])
            ->assertCreated();

        $this->assertSame(
            'wikiconfig',
            Repository::query()->ofType(RepositoryType::Config)->firstOrFail()->versions()->firstOrFail()->path,
        );
    }

    /**
     * @param  array<string, mixed>|null  $config  the config entry the scan reports,
     *                                             or null for a tree without one
     */
    private function scanFinds(?array $config): void
    {
        $this->salt->alwaysRespondTo(StepName::TreeScan, true, [
            'root' => rtrim((string) config('mwdeploy.paths.staging'), '/'),
            'versions' => [],
            'entries' => $config === null ? [] : [$config],
            'counts' => [],
            'warnings' => [],
            'shim_version' => '2.1.0',
        ]);
    }
}
