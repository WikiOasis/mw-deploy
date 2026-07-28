<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeploymentDecision;
use App\Enums\DeploymentStatus;
use App\Enums\StepStatus;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeploymentStep;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three ways to stop a deployment that has not finished on its own:
 * cancelling one that has not started, requesting an abort on one that is
 * already running, and force-failing one the pipeline itself will never
 * resolve. Neither the queued job nor the Salt transport is exercised here —
 * RolloutBehaviourTest covers the runner actually noticing an abort request.
 */
final class DeploymentControlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_pending_deployment_can_be_cancelled(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Pending)->create();

        $response = $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.cancel', $deployment));

        $response->assertOk();

        $deployment->refresh();
        $this->assertSame(DeploymentStatus::Aborted, $deployment->status);
        $this->assertStringContainsString('Cancelled', (string) $deployment->failure_reason);
        $this->assertNotNull($deployment->finished_at);
    }

    #[Test]
    public function cancelling_requires_the_decide_permission(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Pending)->create();

        $this->actingAs($this->userWithPermissions([]))
            ->postJson(route('api.deployments.cancel', $deployment))
            ->assertForbidden();

        $this->assertSame(DeploymentStatus::Pending, $deployment->fresh()->status);
    }

    #[Test]
    public function a_deployment_that_already_started_cannot_be_cancelled(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.cancel', $deployment))
            ->assertForbidden();
    }

    #[Test]
    public function a_running_deployment_can_have_an_abort_requested(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $response = $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.abort', $deployment), [
                'decision' => DeploymentDecision::AbortAndRollback->value,
            ]);

        $response->assertOk();

        $deployment->refresh();
        $this->assertNotNull($deployment->abort_requested_at);
        $this->assertTrue($deployment->abort_rollback);

        // Still running: the runner has not had a chance to notice yet, and
        // aborting is a request the runner honours at its next checkpoint, not an
        // instant status change.
        $this->assertSame(DeploymentStatus::Running, $deployment->status);
    }

    #[Test]
    public function abort_only_does_not_ask_for_a_rollback(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.abort', $deployment), [
                'decision' => DeploymentDecision::Abort->value,
            ])
            ->assertOk();

        $this->assertFalse($deployment->fresh()->abort_rollback);
    }

    #[Test]
    public function abort_rejects_continue_as_a_decision(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.abort', $deployment), [
                'decision' => DeploymentDecision::Continue->value,
            ])
            ->assertUnprocessable();

        $this->assertNull($deployment->fresh()->abort_requested_at);
    }

    #[Test]
    public function aborting_requires_the_decide_permission(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $this->actingAs($this->userWithPermissions([]))
            ->postJson(route('api.deployments.abort', $deployment), [
                'decision' => DeploymentDecision::Abort->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_deployment_that_is_not_running_refuses_an_abort_request(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Done)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.abort', $deployment), [
                'decision' => DeploymentDecision::Abort->value,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function force_failing_a_stuck_deployment_releases_the_fleet_wide_deploy_lock(): void
    {
        Queue::fake();

        $stuck = Deployment::factory()->status(DeploymentStatus::Running)->create();

        // Claims the fleet-wide lock, standing in for the original dispatch that
        // put $stuck's job on the queue in the first place.
        RunDeployment::dispatch($stuck->getKey());
        Queue::assertPushed(RunDeployment::class, 1);

        // A second deployment's job cannot even reach the queue while the lock is
        // held — this is the silent failure this feature exists to recover from.
        $blocked = Deployment::factory()->status(DeploymentStatus::Pending)->create();
        RunDeployment::dispatch($blocked->getKey());
        Queue::assertPushed(RunDeployment::class, 1);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_FORCE_FAIL]))
            ->postJson(route('api.deployments.force-fail', $stuck))
            ->assertOk();

        $this->assertSame(DeploymentStatus::Failed, $stuck->fresh()->status);
        $this->assertStringContainsString('Force-failed', (string) $stuck->fresh()->failure_reason);
        $this->assertNotNull($stuck->fresh()->finished_at);

        // The lock is free again: a fresh dispatch now actually lands.
        RunDeployment::dispatch($blocked->getKey());
        Queue::assertPushed(RunDeployment::class, 2);
    }

    #[Test]
    public function force_failing_retires_steps_still_in_flight(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();
        $pending = DeploymentStep::factory()->create([
            'deployment_id' => $deployment->getKey(),
            'status' => StepStatus::Pending->value,
        ]);
        $running = DeploymentStep::factory()->create([
            'deployment_id' => $deployment->getKey(),
            'status' => StepStatus::Running->value,
        ]);
        $done = DeploymentStep::factory()->create([
            'deployment_id' => $deployment->getKey(),
            'status' => StepStatus::Done->value,
        ]);

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_FORCE_FAIL]))
            ->postJson(route('api.deployments.force-fail', $deployment))
            ->assertOk();

        $this->assertSame(StepStatus::Skipped, $pending->fresh()->status);
        $this->assertSame(StepStatus::Skipped, $running->fresh()->status);
        $this->assertSame(StepStatus::Done, $done->fresh()->status);
    }

    #[Test]
    public function force_failing_requires_the_dedicated_permission_not_decide(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Running)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_DECIDE]))
            ->postJson(route('api.deployments.force-fail', $deployment))
            ->assertForbidden();

        $this->assertSame(DeploymentStatus::Running, $deployment->fresh()->status);
    }

    #[Test]
    public function a_terminal_deployment_cannot_be_force_failed(): void
    {
        $deployment = Deployment::factory()->status(DeploymentStatus::Done)->create();

        $this->actingAs($this->userWithPermissions([Permissions::DEPLOY_FORCE_FAIL]))
            ->postJson(route('api.deployments.force-fail', $deployment))
            ->assertForbidden();
    }
}
