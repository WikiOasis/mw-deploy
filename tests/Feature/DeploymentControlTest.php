<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DecisionReason;
use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Enums\RefType;
use App\Enums\StepName;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\Repository;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The live-dashboard controls: answering a blocking prompt, rolling back, and
 * manual depool/repool.
 */
final class DeploymentControlTest extends TestCase
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
    public function answering_a_prompt_writes_the_row_the_job_is_polling(): void
    {
        $deployment = $this->awaitingDeployment();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->post(route('deployments.decision', $deployment), ['decision' => 'abort_and_rollback'])
            ->assertRedirect();

        $deployment->refresh();

        $this->assertSame(DeploymentDecision::AbortAndRollback, $deployment->decision_response);
        $this->assertNotNull($deployment->decision_answered_at);
        $this->assertNotNull($deployment->decision_by);
    }

    #[Test]
    public function someone_without_the_decide_permission_cannot_answer(): void
    {
        $deployment = $this->awaitingDeployment();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE]))
            ->post(route('deployments.decision', $deployment), ['decision' => 'continue'])
            ->assertForbidden();

        $this->assertNull($deployment->fresh()->decision_response);
    }

    #[Test]
    public function a_deployment_that_is_not_waiting_cannot_be_answered(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Done)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->post(route('deployments.decision', $deployment), ['decision' => 'continue'])
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_decision_is_rejected(): void
    {
        $deployment = $this->awaitingDeployment();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->post(route('deployments.decision', $deployment), ['decision' => 'yolo'])
            ->assertSessionHasErrors('decision');
    }

    #[Test]
    public function rollback_needs_only_the_rollback_permission_not_deploy(): void
    {
        $deployment = $this->rollbackableDeployment();

        // Deliberately broader than deploying: whoever notices a problem should
        // be able to revert without waiting for a forward-deploy approver.
        $responder = $this->userWithPermissions([Permissions::DEPLOY_ROLLBACK]);

        $this->actingAs($responder)
            ->post(route('deployments.rollback', $deployment))
            ->assertRedirect();

        $rollback = Deployment::query()->where('rolls_back_deployment_id', $deployment->getKey())->firstOrFail();

        $this->assertSame($responder->getKey(), $rollback->created_by);
    }

    #[Test]
    public function rollback_is_refused_without_the_permission(): void
    {
        $deployment = $this->rollbackableDeployment();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE]))
            ->post(route('deployments.rollback', $deployment))
            ->assertForbidden();

        $this->assertSame(0, Deployment::query()->whereNotNull('rolls_back_deployment_id')->count());
    }

    #[Test]
    public function a_deployment_with_no_undo_point_offers_no_rollback(): void
    {
        $repository = Repository::factory()->create();

        $deployment = Deployment::factory()->status(DeploymentStatus::Failed)->create();
        $deployment->repoRefs()->create([
            'repository_id' => $repository->getKey(),
            'ref_type' => RefType::Branch->value,
            'ref_value' => 'master',
        ]);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_ROLLBACK]))
            ->post(route('deployments.rollback', $deployment))
            ->assertForbidden();
    }

    #[Test]
    public function a_rollback_cannot_itself_be_rolled_back_from_the_ui(): void
    {
        $original = $this->rollbackableDeployment();

        $rollback = Deployment::factory()->status(DeploymentStatus::Failed)->create([
            'rolls_back_deployment_id' => $original->getKey(),
        ]);
        $rollback->snapshots()->create([
            'repository_id' => $original->snapshots()->firstOrFail()->repository_id,
            'previous_ref_type' => RefType::Commit->value,
            'previous_ref_value' => 'newsha',
            'new_ref_type' => RefType::Commit->value,
            'new_ref_value' => 'oldsha',
        ]);

        // Capping the chain at one hop is what stops a bad deploy oscillating.
        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_ROLLBACK]))
            ->post(route('deployments.rollback', $rollback))
            ->assertForbidden();
    }

    #[Test]
    public function the_history_view_warns_about_an_out_of_order_rollback(): void
    {
        $repository = Repository::factory()->create();

        $older = $this->rollbackableDeployment($repository);
        $newer = Deployment::factory()->status(DeploymentStatus::Done)->create();
        $newer->repoRefs()->create([
            'repository_id' => $repository->getKey(),
            'ref_type' => RefType::Branch->value,
            'ref_value' => 'master',
        ]);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_ROLLBACK]))
            ->get(route('deployments.show', $older))
            ->assertOk()
            ->assertSee('out of order');
    }

    #[Test]
    public function manual_depool_hits_every_registered_proxy(): void
    {
        $server = DeployTarget::factory()->create(['hostname' => 'mw-01']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-2']);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_POOL]))
            ->post(route('targets.pool', $server), ['action' => 'depool'])
            ->assertSessionHasNoErrors();

        $this->assertCount(2, $this->salt->callsFor(StepName::HaproxyDepool));
        $this->salt->assertRan(StepName::HaproxyDepool, 'proxy-1');
        $this->salt->assertRan(StepName::HaproxyDepool, 'proxy-2');
    }

    #[Test]
    public function manual_pooling_reports_a_proxy_that_refused(): void
    {
        $server = DeployTarget::factory()->create();
        DeployTarget::factory()->proxy()->create(['hostname' => 'proxy-1']);

        $this->salt->alwaysRespondTo(StepName::HaproxyRepool, false);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_POOL]))
            ->post(route('targets.pool', $server), ['action' => 'repool'])
            ->assertSessionHasErrors('action');
    }

    #[Test]
    public function manual_pooling_is_refused_without_the_pool_permission(): void
    {
        $server = DeployTarget::factory()->create();
        DeployTarget::factory()->proxy()->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_CORE]))
            ->post(route('targets.pool', $server), ['action' => 'depool'])
            ->assertForbidden();

        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function a_proxy_cannot_be_pooled_as_if_it_were_an_appserver(): void
    {
        $proxy = DeployTarget::factory()->proxy()->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_POOL]))
            ->post(route('targets.pool', $proxy), ['action' => 'depool'])
            ->assertSessionHasErrors('action');

        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_state_endpoint_serves_the_polling_fallback(): void
    {
        $deployment = $this->awaitingDeployment();
        $deployment->steps()->create([
            'target_hostname' => 'mw-01',
            'step_name' => StepName::RsyncRemote->value,
            'status' => 'running',
            'sequence' => 1,
            'started_at' => now(),
            'log' => 'sending incremental file list',
        ]);

        $this->actingAs($this->userWithPermissions([]))
            ->get(route('deployments.state', $deployment))
            ->assertOk()
            ->assertJsonPath('awaiting_decision', true)
            ->assertJsonPath('pending_decision', DecisionReason::ServerCanaryFailed->value)
            ->assertJsonPath('steps.0.host', 'mw-01')
            ->assertJsonPath('steps.0.status', 'running');
    }

    private function awaitingDeployment(): Deployment
    {
        return Deployment::factory()->status(DeploymentStatus::Running)->create([
            'pending_decision' => DecisionReason::ServerCanaryFailed->value,
            'pending_decision_context' => ['host' => 'mw-01', 'detail' => 'HTTP 503'],
            'pending_decision_requested_at' => now(),
        ]);
    }

    private function rollbackableDeployment(?Repository $repository = null): Deployment
    {
        $repository ??= Repository::factory()->create();

        $deployment = Deployment::factory()->status(DeploymentStatus::Done)->create();

        $deployment->repoRefs()->create([
            'repository_id' => $repository->getKey(),
            'ref_type' => RefType::Branch->value,
            'ref_value' => 'master',
        ]);

        $deployment->snapshots()->create([
            'repository_id' => $repository->getKey(),
            'previous_ref_type' => RefType::Commit->value,
            'previous_ref_value' => 'oldsha',
            'new_ref_type' => RefType::Branch->value,
            'new_ref_value' => 'master',
        ]);

        return $deployment;
    }
}
