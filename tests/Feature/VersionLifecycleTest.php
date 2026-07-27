<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Deployments\RollbackDeployment;
use App\Actions\Versions\CreateVersion;
use App\Actions\Versions\UndeployVersion;
use App\Enums\DeploymentIntent;
use App\Enums\DeploymentStatus;
use App\Enums\PresenceStatus;
use App\Enums\RepoAction;
use App\Enums\StepName;
use App\Jobs\RunDeployment;
use App\Models\Deployment;
use App\Models\DeployTarget;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Models\User;
use App\Services\Deployment\DeploymentAuthorizer;
use App\Services\Deployment\DeploymentRunner;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\DeploymentOptions;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AutoAnsweringDecisionGate;
use Tests\TestCase;

/**
 * Cutting a new core version by reconstruction, and removing one.
 */
final class VersionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    private AutoAnsweringDecisionGate $decisions;

    private User $actor;

    private MediaWikiVersion $v45;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mwdeploy.targets.staging' => 'staging',
            'mwdeploy.shim.binary' => '/usr/local/bin/mwdeploy-shim',
            'mwdeploy.paths.staging' => '/srv/mediawiki-staging',
            'mwdeploy.paths.production' => '/srv/mediawiki',
            'mwdeploy.paths.wiki_versions' => '/srv/mediawiki/config/wikiversions.json',
            'mwdeploy.decisions.timeout' => 0,
        ]);

        Queue::fake();

        $this->salt = $this->fakeSalt();
        $this->decisions = $this->fakeDecisions();
        $this->actor = $this->admin();

        $this->v45 = $this->version('1.45');
        $this->core($this->v45, 'REL1_45');
    }

    #[Test]
    public function cutting_a_version_copies_the_source_versions_extension_set(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        $this->extension('Thanks', $this->v45, 'master');

        // A skin comes along too; config does not — it lives outside version trees.
        $skin = Repository::factory()->skin()->create(['name' => 'Vector']);
        RepositoryVersion::factory()->of($skin, $this->v45)->pinnedTo('REL1_45')->create();

        $outcome = $this->create('1.46', $this->v45, 'REL1_46');

        $this->assertNull($outcome['error']);

        $deployment = $outcome['deployment'];

        // Core plus Echo, Thanks and Vector.
        $this->assertSame(4, $deployment->repoRefs()->count());
        $this->assertSame(DeploymentIntent::VersionCreate, $deployment->intent);

        $names = $deployment->repoRefs()->with('repositoryVersion.repository')->get()
            ->map(fn ($ref) => $ref->repositoryVersion->repository->name)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Echo', 'Thanks', 'Vector', 'mediawiki'], $names);
    }

    #[Test]
    public function the_new_version_is_not_present_until_the_deployment_has_built_it(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');

        $outcome = $this->create('1.46', $this->v45, 'REL1_46');

        $version = $outcome['version'];

        // Claiming a version exists before anything has been cloned would make the
        // repo browser lie.
        $this->assertSame(PresenceStatus::Undeployed, $version->status);
        $this->assertFalse($version->isPresent());

        $this->runJob($outcome['deployment']->fresh());

        $this->assertSame(PresenceStatus::Present, $version->fresh()->status);
    }

    #[Test]
    public function it_scaffolds_the_version_tree_before_cloning_into_it(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');

        $outcome = $this->create('1.46', $this->v45, 'REL1_46');

        $this->runJob($outcome['deployment']->fresh());

        $sequence = $this->salt->stepSequence();
        $scaffoldAt = array_search(StepName::VersionScaffold->value, $sequence, true);
        $firstRegisterAt = array_search(StepName::RepoRegister->value, $sequence, true);

        $this->assertNotFalse($scaffoldAt);
        $this->assertLessThan($firstRegisterAt, $scaffoldAt);

        $this->assertStringContainsString(
            "'--path' '/srv/mediawiki-staging/versions/1.46' '--version' '1.46'",
            $this->salt->callsFor(StepName::VersionScaffold)[0]->command->toString(),
        );
    }

    #[Test]
    public function the_copied_checkouts_keep_the_source_versions_pins_unless_overridden(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');

        $outcome = $this->create('1.46', $this->v45, 'REL1_46', overrides: []);

        $echoRef = $outcome['deployment']->repoRefs()
            ->with('repositoryVersion.repository')
            ->get()
            ->first(fn ($ref) => $ref->repositoryVersion->repository->name === 'Echo');

        // Carrying the pin forward is right for a moving branch and wrong for a
        // release branch, which is exactly why the review screen lists every ref.
        $this->assertSame('REL1_45', $echoRef->ref_value);
    }

    #[Test]
    public function a_per_repository_override_wins(): void
    {
        $echo = $this->extension('Echo', $this->v45, 'REL1_45');

        $outcome = $this->create('1.46', $this->v45, 'REL1_46', overrides: [
            $echo->repository_id => ['ref' => 'REL1_46'],
        ]);

        $echoRef = $outcome['deployment']->repoRefs()
            ->with('repositoryVersion.repository')
            ->get()
            ->first(fn ($ref) => $ref->repositoryVersion->repository->name === 'Echo');

        $this->assertSame('REL1_46', $echoRef->ref_value);
    }

    #[Test]
    public function it_refuses_a_duplicate_or_malformed_version(): void
    {
        $this->assertSame('Version 1.45 already exists.', $this->create('1.45', null, 'REL1_45')['error']);
        $this->assertStringContainsString('not a MediaWiki version number', $this->create('nope', null, 'x')['error']);
        $this->assertSame(1, MediaWikiVersion::query()->count());
    }

    #[Test]
    public function cutting_a_version_only_touches_staging_by_default(): void
    {
        DeployTarget::factory()->create();
        $this->extension('Echo', $this->v45, 'REL1_45');

        $outcome = $this->create('1.46', $this->v45, 'REL1_46');

        $this->runJob($outcome['deployment']->fresh());

        // A brand new version serves no traffic, so build and check it before it
        // reaches any appserver.
        $this->salt->assertNeverRan(StepName::RsyncRemote);
    }

    #[Test]
    public function undeploying_a_version_removes_the_whole_subtree_in_one_call_per_host(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        $this->extension('Thanks', $this->v45, 'master');
        DeployTarget::factory()->create(['hostname' => 'mw-01']);

        $this->allowVersionRemoval();

        $deployment = $this->undeployVersion();

        $this->runJob($deployment);

        $this->assertSame(DeploymentStatus::Done, $deployment->fresh()->status);

        $removals = $this->salt->callsFor(StepName::RepoRemove);

        // Staging tree, staging's production copy, and the appserver — three calls,
        // not three per checkout inside the version.
        $this->assertCount(3, $removals);

        foreach ($removals as $call) {
            $command = $call->command->toString();
            $this->assertStringContainsString("'--allow-version-root'", $command);
            $this->assertStringContainsString('versions/1.45', $command);
        }
    }

    #[Test]
    public function undeploying_a_version_marks_every_checkout_inside_it_undeployed(): void
    {
        $echo = $this->extension('Echo', $this->v45, 'REL1_45');
        $thanks = $this->extension('Thanks', $this->v45, 'master');
        DeployTarget::factory()->create();

        $this->allowVersionRemoval();

        $this->runJob($this->undeployVersion());

        $this->assertSame(PresenceStatus::Undeployed, $echo->fresh()->status);
        $this->assertSame(PresenceStatus::Undeployed, $thanks->fresh()->status);
        $this->assertSame(PresenceStatus::Undeployed, $this->v45->fresh()->status);
    }

    #[Test]
    public function it_refuses_to_remove_a_version_wikis_still_run_on(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::WikiVersions, true, [
            'versions' => ['1.45' => ['metawiki', 'testwiki']],
        ]);

        $deployment = $this->undeployVersion();

        $this->runJob($deployment);

        $deployment->refresh();

        $this->assertSame(DeploymentStatus::Failed, $deployment->status);
        $this->assertStringContainsString('2 wiki(s) still run on 1.45', (string) $deployment->failure_reason);
        $this->assertStringContainsString('metawiki', (string) $deployment->failure_reason);

        // Nothing was deleted, and the registry still says the version is there.
        $this->salt->assertNeverRan(StepName::RepoRemove);
        $this->assertSame(PresenceStatus::Present, $this->v45->fresh()->status);
    }

    #[Test]
    public function it_fails_closed_when_the_wiki_version_map_cannot_be_read(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        DeployTarget::factory()->create();

        $this->salt->alwaysRespondTo(StepName::WikiVersions, false);

        $deployment = $this->undeployVersion();

        $this->runJob($deployment);

        // Guessing here would mean deleting a version that might be serving.
        $this->assertSame(DeploymentStatus::Failed, $deployment->fresh()->status);
        $this->assertStringContainsString('Could not read the wiki version map', (string) $deployment->fresh()->failure_reason);
        $this->salt->assertNeverRan(StepName::RepoRemove);
    }

    #[Test]
    public function the_check_can_be_disabled_for_a_farm_whose_map_is_unreachable(): void
    {
        config(['mwdeploy.versions.require_wiki_version_check' => false]);

        $this->extension('Echo', $this->v45, 'REL1_45');
        DeployTarget::factory()->create();

        $this->runJob($this->undeployVersion());

        $this->salt->assertNeverRan(StepName::WikiVersions);
        $this->assertSame(PresenceStatus::Undeployed, $this->v45->fresh()->status);
    }

    #[Test]
    public function undoing_a_version_removal_rebuilds_every_checkout(): void
    {
        $echo = $this->extension('Echo', $this->v45, 'REL1_45');
        $thanks = $this->extension('Thanks', $this->v45, 'master');
        DeployTarget::factory()->create();

        $this->allowVersionRemoval();
        $this->salt->alwaysRespondTo(StepName::GitHead, true, ['ref' => 'REL1_45', 'ref_type' => 'branch']);

        $deployment = $this->undeployVersion();
        $this->runJob($deployment);

        $rollback = app(RollbackDeployment::class)($deployment->fresh(), $this->actor, dispatch: false);

        $this->assertNotNull($rollback);
        // Reversing a removal is a creation.
        $this->assertSame(DeploymentIntent::VersionCreate, $rollback->intent);
        $this->assertSame($this->v45->getKey(), $rollback->mediawiki_version_id);

        // Core, Echo and Thanks: rebuilding the version means rebuilding core too,
        // not just the extensions that happened to be inside it.
        $this->assertSame(3, $rollback->repoRefs()->count());
        $this->assertContains(
            'mediawiki',
            $rollback->repoRefs()->with('repositoryVersion.repository')->get()
                ->map(fn ($ref) => $ref->repositoryVersion->repository->name)
                ->all(),
        );

        foreach ($rollback->repoRefs as $ref) {
            $this->assertSame(RepoAction::Deploy, $ref->action);
            $this->assertSame('REL1_45', $ref->ref_value);
        }

        $this->runJob($rollback->fresh());

        $this->assertSame(PresenceStatus::Present, $echo->fresh()->status);
        $this->assertSame(PresenceStatus::Present, $thanks->fresh()->status);
        $this->assertSame(PresenceStatus::Present, $this->v45->fresh()->status);
    }

    #[Test]
    public function the_job_refuses_a_version_removal_from_someone_without_that_permission(): void
    {
        $this->extension('Echo', $this->v45, 'REL1_45');
        DeployTarget::factory()->create();

        $this->actor = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::UNDEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $deployment = $this->undeployVersion();

        $this->runJob($deployment);

        // Removing an extension is not the same grant as removing a whole version.
        $this->assertStringContainsString(Permissions::UNDEPLOY_VERSION, (string) $deployment->fresh()->failure_reason);
        $this->assertSame([], $this->salt->calls());
    }

    #[Test]
    public function the_job_refuses_a_version_create_without_versions_manage(): void
    {
        $this->actor = $this->userWithPermissions([
            Permissions::DEPLOY_CORE,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $outcome = $this->create('1.46', $this->v45, 'REL1_46');

        $this->runJob($outcome['deployment']->fresh());

        $this->assertStringContainsString(
            Permissions::VERSIONS_MANAGE,
            (string) $outcome['deployment']->fresh()->failure_reason,
        );
    }

    /**
     * @param  array<int, array{ref_mode?: string, ref?: string|null}>  $overrides
     * @return array{version: MediaWikiVersion|null, deployment: Deployment|null, error: string|null}
     */
    private function create(string $version, ?MediaWikiVersion $source, string $coreRef, array $overrides = []): array
    {
        return app(CreateVersion::class)(
            actor: $this->actor,
            version: $version,
            source: $source,
            coreRef: $coreRef,
            options: new DeploymentOptions(stagingOnly: true),
            overrides: $overrides,
            dispatch: false,
        );
    }

    private function undeployVersion(): Deployment
    {
        $outcome = app(UndeployVersion::class)(
            actor: $this->actor,
            version: $this->v45,
            options: new DeploymentOptions,
            dispatch: false,
        );

        $this->assertNull($outcome['error']);

        return $outcome['deployment']->fresh();
    }

    /** No wiki uses the version, so the guard lets the removal through. */
    private function allowVersionRemoval(): void
    {
        $this->salt->alwaysRespondTo(StepName::WikiVersions, true, ['versions' => ['1.46' => ['metawiki']]]);
    }

    private function runJob(Deployment $deployment): void
    {
        (new RunDeployment($deployment->getKey()))->handle(
            app(DeploymentRunner::class),
            app(DeploymentAuthorizer::class),
        );
    }
}
