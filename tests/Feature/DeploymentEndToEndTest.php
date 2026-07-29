<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RepoAction;
use App\Enums\StepName;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\User;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AutoAnsweringDecisionGate;
use Tests\TestCase;

/**
 * The whole wizard, front to back: the real HTTP endpoints the SPA calls
 * (options → plan → store), not a deployment built straight from factories, then
 * the queued job actually run against it. Every other test in this suite proves
 * DeploymentRunner behaves correctly once handed a Deployment; this one proves
 * the request that builds that Deployment in the first place actually leads to
 * steps being queued and executed — the gap that let the undeploy confirm-step
 * validation bug (and its knock-on effects) go unnoticed.
 */
final class DeploymentEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private MediaWikiVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mwdeploy.targets.staging' => 'staging',
            'mwdeploy.shim.binary' => '/usr/local/bin/mwdeploy-shim',
            'mwdeploy.paths.staging' => '/srv/mediawiki-staging',
            'mwdeploy.paths.production' => '/srv/mediawiki',
            'mwdeploy.decisions.timeout' => 0,
        ]);

        Queue::fake();

        $this->salt = $this->fakeSalt();
        $this->decisions = $this->fakeDecisions();
        $this->version = $this->version('1.45');
    }

    #[Test]
    public function a_deploy_planned_and_confirmed_through_the_wizard_actually_queues_and_runs_its_steps(): void
    {
        $echo = $this->extension('Echo', $this->version, 'REL1_45');
        $server = DeployTarget::factory()->create();
        $deployer = $this->deployer();

        $plan = $this->actingAs($deployer)
            ->postJson(route('api.deployments.plan'), [
                'intent' => 'deploy',
                'items' => [[
                    'repository_version_id' => $echo->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'REL1_45',
                ]],
                'servers' => [$server->hostname],
                'parallel' => 1,
                'staging_only' => false,
            ])
            ->assertOk()
            ->json('payload');

        $response = $this->actingAs($deployer)
            ->postJson(route('api.deployments.store'), $plan)
            ->assertCreated();

        Queue::assertPushed(RunDeployment::class);

        $deployment = Deployment::query()->findOrFail($response->json('id'));
        $this->assertSame(DeploymentStatus::Pending, $deployment->status);
        $this->assertSame(0, $deployment->steps()->count());

        $this->runJob($deployment);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Done, $deployment->status);

        // The actual point of this test: steps were queued and executed, not just
        // a Deployment row with no work behind it.
        $this->assertGreaterThan(0, $deployment->steps()->count());
        $this->assertGreaterThan(0, count($this->salt->calls()));
        $this->salt->assertRan(StepName::GitCheckout, 'staging');
        $this->salt->assertRan(StepName::RsyncRemote, $server->hostname);
    }

    #[Test]
    public function a_job_that_cannot_acquire_the_staging_tree_lock_fails_without_touching_salt(): void
    {
        $echo = $this->extension('Echo', $this->version, 'REL1_45');
        $server = DeployTarget::factory()->create();
        $deployer = $this->deployer();

        $plan = $this->actingAs($deployer)
            ->postJson(route('api.deployments.plan'), [
                'intent' => 'deploy',
                'items' => [[
                    'repository_version_id' => $echo->getKey(),
                    'ref_type' => 'branch',
                    'ref_value' => 'REL1_45',
                ]],
                'servers' => [$server->hostname],
                'parallel' => 1,
                'staging_only' => false,
            ])
            ->assertOk()
            ->json('payload');

        $response = $this->actingAs($deployer)
            ->postJson(route('api.deployments.store'), $plan)
            ->assertCreated();

        $deployment = Deployment::query()->findOrFail($response->json('id'));

        // Standing in for another worker genuinely mid-deployment right now —
        // "only ever run one worker" (docs/OPERATIONS.md) means this should only
        // ever happen under a misconfiguration, which is exactly what this
        // guards against rather than letting two runs stomp the same checkout.
        $lock = Cache::lock(RunDeployment::LOCK_KEY, RunDeployment::LOCK_TTL);
        $this->assertTrue($lock->get());

        $this->runJob($deployment);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('running concurrently', (string) $deployment->failure_reason);
        $this->assertSame(0, $deployment->steps()->count());
        $this->assertSame([], $this->salt->calls());

        $lock->release();
    }

    #[Test]
    public function an_undeploy_planned_and_confirmed_through_the_wizard_actually_queues_and_runs_its_steps(): void
    {
        $echo = $this->extension('Echo', $this->version, 'REL1_45');
        $server = DeployTarget::factory()->create();
        $remover = $this->remover();

        $plan = $this->actingAs($remover)
            ->postJson(route('api.deployments.plan'), [
                'intent' => 'undeploy',
                'items' => [['repository_version_id' => $echo->getKey()]],
                'servers' => [$server->hostname],
                'parallel' => 1,
                'staging_only' => false,
            ])
            ->assertOk()
            ->json('payload');

        // The echoed items carry an explicit ref_value of null rather than
        // omitting the key — this is exactly the shape that used to fail
        // validation outright (see StoreDeploymentRequest::rules()).
        $this->assertNull($plan['items'][0]['ref_value']);

        $response = $this->actingAs($remover)
            ->postJson(route('api.deployments.store'), $plan)
            ->assertCreated();

        Queue::assertPushed(RunDeployment::class);

        $deployment = Deployment::query()->findOrFail($response->json('id'));
        $this->assertSame(RepoAction::Undeploy, $deployment->repoRefs()->firstOrFail()->action);
        $this->assertSame(0, $deployment->steps()->count());

        $this->runJob($deployment);

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Done, $deployment->status);

        $this->assertGreaterThan(0, $deployment->steps()->count());
        $this->salt->assertRan(StepName::RepoRemove, 'staging');
        $this->salt->assertRan(StepName::RepoRemove, $server->hostname);
        $this->assertSame(PresenceStatus::Undeployed, $echo->fresh()->status);
    }

    private function runJob(Deployment $deployment): void
    {
        (new RunDeployment($deployment->getKey()))->handle(
            app(DeploymentRunner::class),
            app(DeploymentAuthorizer::class),
        );
    }

    private function deployer(): User
    {
        return $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);
    }

    private function remover(): User
    {
        return $this->userWithPermissions([
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);
    }
}
