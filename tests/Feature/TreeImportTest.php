<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Import\ApplyImport;
use App\Enums\PresenceStatus;
use App\Enums\RefMode;
use App\Enums\RefType;
use App\Enums\RepositoryType;
use App\Enums\StepName;
use App\Models\Deployment;
use App\Models\MediaWikiVersion;
use App\Models\Repository;
use App\Models\RepositoryVersion;
use App\Services\Discovery\ImportAction;
use App\Services\Discovery\ImportPlan;
use App\Services\Discovery\ImportPlanner;
use App\Services\Discovery\ScanFailed;
use App\Services\Discovery\TreeScanner;
use App\Services\Salt\SaltAsyncStartFailed;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Adopting a MediaWiki farm the portal did not build.
 *
 * The premise throughout: the code is already on disk. Import writes registry rows
 * that describe it and never touches the tree — no clone, no checkout, no rsync,
 * no removal. Every test here asserts one or both halves of that.
 */
final class TreeImportTest extends TestCase
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
    public function it_registers_every_version_extension_and_skin_it_finds(): void
    {
        $this->respondWithScan();

        $plan = $this->plan();

        $this->assertSame(
            ['1.45', '1.46'],
            $plan->entries
                ->filter(fn ($entry) => $entry->action === ImportAction::CreateVersion)
                ->pluck('version')
                ->sort()
                ->values()
                ->all(),
        );

        $outcome = app(ApplyImport::class)($plan, $this->admin());

        $this->assertSame(2, $outcome['versions']);
        $this->assertSame(4, $outcome['repositories']); // mediawiki, Echo, Thanks, Vector
        $this->assertSame(6, $outcome['checkouts']);

        $echo = Repository::query()->where('name', 'Echo')->firstOrFail();

        $this->assertSame(RepositoryType::Extension, $echo->type);
        $this->assertSame('https://github.com/wikioasis/mediawiki-extensions-Echo.git', $echo->git_url);
        // extension.json's declared name is kept for display, not used as the
        // directory: the farm keeps Notifications in a directory called Echo.
        $this->assertSame('Notifications', $echo->manifestName());

        $checkouts = $echo->versions()->with('mediawikiVersion')->get();

        $this->assertSame(['1.45', '1.46'], $checkouts->pluck('mediawikiVersion.version')->sort()->values()->all());
        $this->assertSame(
            ['REL1_45', 'REL1_46'],
            $checkouts->pluck('tracked_ref_value')->sort()->values()->all(),
        );

        // Imported checkouts are already deployed. Recording them as undeployed
        // would make the first deployment of each one a clone over a live tree.
        $checkouts->each(function (RepositoryVersion $checkout): void {
            $this->assertTrue($checkout->isPresent());
            $this->assertSame(RefMode::Pinned, $checkout->ref_mode);
            $this->assertNotNull($checkout->discovered_at);
        });
    }

    #[Test]
    public function importing_never_touches_the_tree(): void
    {
        $this->respondWithScan();

        app(ApplyImport::class)($this->plan(), $this->admin());

        // One tree-scan, and nothing else. In particular no repo-register (clone),
        // no git-checkout and no rsync.
        $this->assertSame([StepName::TreeScan->value], $this->salt->stepSequence());

        $this->assertSame(0, Deployment::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_records_the_core_version_it_read_from_defines_php(): void
    {
        $this->respondWithScan();

        app(ApplyImport::class)($this->plan(), $this->admin());

        $this->assertSame(
            '1.45.0',
            MediaWikiVersion::query()->where('version', '1.45')->firstOrFail()->core_version,
        );
    }

    #[Test]
    public function a_directory_with_no_git_remote_is_reported_rather_than_registered(): void
    {
        $this->respondWithScan([
            'entries' => [
                $this->coreEntry('1.45'),
                [
                    'kind' => 'extension',
                    'name' => 'HandRolled',
                    'path' => 'versions/1.45/extensions/HandRolled',
                    'version' => '1.45',
                    'is_git' => false,
                ],
            ],
            'versions' => ['1.45'],
            'warnings' => ['versions/1.45/extensions/HandRolled: not a git checkout'],
        ]);

        $plan = $this->plan();
        $entry = $plan->entries->firstWhere('name', 'HandRolled');

        $this->assertSame(ImportAction::Unimportable, $entry->action);
        $this->assertFalse($entry->selectedByDefault());

        app(ApplyImport::class)($plan, $this->admin());

        // Registering it would claim the portal could deploy something it has no
        // remote for, which is worse than leaving it alone.
        $this->assertSame(0, Repository::query()->where('name', 'HandRolled')->count());
    }

    #[Test]
    public function an_existing_registry_entry_is_adopted_rather_than_duplicated(): void
    {
        $version = $this->version('1.45');
        $checkout = $this->extension('Echo', $version, 'REL1_45');
        $checkout->markUndeployed();

        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'REL1_45')],
            'versions' => ['1.45'],
        ]);

        $plan = $this->plan();
        $entry = $plan->entries->firstWhere('key', 'versions/1.45/extensions/Echo');

        $this->assertSame(ImportAction::AdoptCheckout, $entry->action);
        $this->assertTrue($entry->selectedByDefault());

        app(ApplyImport::class)($plan, $this->admin());

        $this->assertSame(1, Repository::query()->where('name', 'Echo')->count());
        $this->assertTrue($checkout->fresh()->isPresent());
    }

    #[Test]
    public function a_checkout_that_matches_its_pin_is_reported_as_in_sync_and_does_nothing(): void
    {
        $version = $this->version('1.45');
        $this->extension('Echo', $version, 'REL1_45');

        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'REL1_45')],
            'versions' => ['1.45'],
        ]);

        $plan = $this->plan();

        $this->assertSame(
            ImportAction::InSync,
            $plan->entries->firstWhere('key', 'versions/1.45/extensions/Echo')->action,
        );

        // Nothing recommended for this row, so a blanket import leaves it alone.
        $this->assertNotContains('versions/1.45/extensions/Echo', $plan->recommended()->pluck('key')->all());
    }

    #[Test]
    public function ref_drift_is_surfaced_but_not_repinned_without_being_asked(): void
    {
        $version = $this->version('1.45');
        $checkout = $this->extension('Echo', $version, 'REL1_45');

        // The tree is on a different branch than the registry pins.
        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'wmf/1.45.0-wmf.3')],
            'versions' => ['1.45'],
        ]);

        $plan = $this->plan();
        $entry = $plan->entries->firstWhere('key', 'versions/1.45/extensions/Echo');

        $this->assertSame(ImportAction::Repin, $entry->action);
        // Not recommended: the pin may be deliberate and the tree the thing that is
        // behind. Repinning silently would change what the next deploy does.
        $this->assertFalse($entry->selectedByDefault());

        app(ApplyImport::class)($plan, $this->admin());

        $this->assertSame('REL1_45', $checkout->fresh()->tracked_ref_value);

        // Selecting it explicitly does repin.
        app(ApplyImport::class)($plan, $this->admin(), [$entry->key]);

        $this->assertSame('wmf/1.45.0-wmf.3', $checkout->fresh()->tracked_ref_value);
    }

    #[Test]
    public function the_observation_columns_are_written_even_without_importing(): void
    {
        $version = $this->version('1.45');
        $checkout = $this->extension('Echo', $version, 'REL1_45');

        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'wmf/1.45.0-wmf.3')],
            'versions' => ['1.45'],
        ]);

        app(ApplyImport::class)->recordObservations($this->plan());

        $checkout = $checkout->fresh();

        $this->assertSame('wmf/1.45.0-wmf.3', $checkout->observed_ref_value);
        $this->assertSame(RefType::Branch, $checkout->observed_ref_type);
        $this->assertNotNull($checkout->observed_at);
        // The pin is untouched; drift is a thing to show, not to resolve.
        $this->assertSame('REL1_45', $checkout->tracked_ref_value);
        $this->assertTrue($checkout->hasRefDrift());
    }

    #[Test]
    public function a_checkout_the_tree_does_not_have_is_offered_for_marking_undeployed(): void
    {
        $version = $this->version('1.45');
        $this->extension('Gone', $version, 'REL1_45');

        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45')],
            'versions' => ['1.45'],
        ]);

        $plan = $this->plan();
        $entry = $plan->entries->firstWhere('name', 'Gone');

        $this->assertSame(ImportAction::MarkUndeployed, $entry->action);
        $this->assertFalse($entry->selectedByDefault());

        app(ApplyImport::class)($plan, $this->admin(), [$entry->key]);

        $this->assertSame(
            PresenceStatus::Undeployed,
            RepositoryVersion::query()->whereRelation('repository', 'name', 'Gone')->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_remote_written_two_ways_is_not_reported_as_drift(): void
    {
        $version = $this->version('1.45');
        $checkout = $this->extension('Echo', $version, 'REL1_45');

        $checkout->repository->update(['git_url' => 'https://github.com/wikioasis/mediawiki-extensions-Echo']);

        $this->respondWithScan([
            'entries' => [
                $this->coreEntry('1.45'),
                // Same remote, with the .git suffix the clone actually recorded.
                $this->echoEntry('1.45', 'REL1_45'),
            ],
            'versions' => ['1.45'],
        ]);

        $this->assertSame(
            0,
            $this->plan()->entries->filter(fn ($entry) => $entry->action === ImportAction::UpdateUrl)->count(),
        );
    }

    #[Test]
    public function a_genuine_remote_change_is_offered_but_not_applied_by_default(): void
    {
        $version = $this->version('1.45');
        $checkout = $this->extension('Echo', $version, 'REL1_45');

        $checkout->repository->update(['git_url' => 'https://gerrit.wikimedia.org/r/mediawiki/extensions/Echo']);

        $this->respondWithScan([
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'REL1_45')],
            'versions' => ['1.45'],
        ]);

        $plan = $this->plan();
        $entry = $plan->entries->firstWhere('action', ImportAction::UpdateUrl);

        $this->assertNotNull($entry);
        $this->assertFalse($entry->selectedByDefault());

        app(ApplyImport::class)($plan, $this->admin(), [$entry->key]);

        $this->assertSame(
            'https://github.com/wikioasis/mediawiki-extensions-Echo.git',
            $checkout->repository->fresh()->git_url,
        );
    }

    #[Test]
    public function a_failed_scan_is_an_error_rather_than_an_empty_inventory(): void
    {
        // Failing closed matters here: an empty scan read as truth would produce a
        // plan full of phantom removals.
        $this->salt->respondTo(StepName::TreeScan, false);

        $this->expectException(ScanFailed::class);

        app(TreeScanner::class)->scan(fresh: true);
    }

    #[Test]
    public function the_api_plans_and_applies_behind_the_repository_manage_permission(): void
    {
        $this->respondWithScan();

        $reader = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION]);

        $this->actingAs($reader)->getJson(route('api.import.show'))->assertForbidden();
        $this->actingAs($reader)->postJson(route('api.import.store'), ['keys' => []])->assertForbidden();

        $manager = $this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]);

        $response = $this->actingAs($manager)->getJson(route('api.import.show', ['fresh' => 1]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('plan.root', rtrim((string) config('mwdeploy.paths.staging'), '/'));

        $keys = $response->json('plan.recommended_keys');

        $this->assertNotEmpty($keys);

        $this->actingAs($manager)
            ->postJson(route('api.import.store'), ['keys' => $keys, 'fresh' => true])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(4, Repository::query()->count());
        $this->assertSame(2, MediaWikiVersion::query()->count());
    }

    #[Test]
    public function re_scanning_always_asks_salt_again_rather_than_reusing_the_last_scan(): void
    {
        $this->respondWithScan();

        $admin = $this->admin();

        $this->actingAs($admin)->getJson(route('api.import.show', ['fresh' => 1]))->assertOk();
        // A second "Re-scan" moments later, well inside the in-flight scan's TTL —
        // it must not silently reuse the first scan's job.
        $this->actingAs($admin)->getJson(route('api.import.show', ['fresh' => 1]))->assertOk();

        $this->assertCount(2, $this->salt->callsFor(StepName::TreeScan));
    }

    #[Test]
    public function an_older_scan_finishing_after_a_newer_one_does_not_clobber_the_shared_cache(): void
    {
        $scanner = app(TreeScanner::class);
        $root = rtrim((string) config('mwdeploy.paths.staging'), '/');

        // Two distinct, single-use responses so each startScan() gets its own —
        // respondTo() (unlike alwaysRespondTo()) is consumed after firing once.
        $this->salt->respondTo(StepName::TreeScan, true, payload: [
            'root' => $root, 'versions' => [], 'entries' => [], 'warnings' => ['scan A'],
        ]);
        $this->salt->respondTo(StepName::TreeScan, true, payload: [
            'root' => $root, 'versions' => [], 'entries' => [], 'warnings' => ['scan B'],
        ]);

        $scanIdA = $scanner->startScan(fresh: true);

        // A second "Re-scan" supersedes A as the current pointer before A is
        // ever polled — e.g. A is slow and the operator clicks Re-scan again.
        $scanIdB = $scanner->startScan(fresh: true);

        // B is polled first and legitimately updates the shared cache.
        $scanner->pollScan($scanIdB);
        // A finishes after B but must not overwrite it — A is no longer current.
        $scanner->pollScan($scanIdA);

        $this->assertSame(['scan B'], $scanner->cached()->warnings);
    }

    #[Test]
    public function the_api_reports_a_scan_failure_with_a_hint_rather_than_an_empty_plan(): void
    {
        $this->salt->respondTo(StepName::TreeScan, false);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.import.show', ['fresh' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonStructure(['error', 'hint']);

        // The shim on staging is the default suspect for a scan that reached it.
        $this->assertStringContainsString('tree-scan', $response->json('hint'));
        $this->assertStringContainsString((string) config('mwdeploy.targets.staging'), $response->json('hint'));
    }

    #[Test]
    public function a_salt_cli_that_never_ran_points_at_the_portal_host_not_at_staging(): void
    {
        /*
         * The salt CLI creates `~/.salt` while parsing its arguments, so run as a
         * user with an unwritable home it exits 64 before contacting anything. That
         * is a problem on the portal host, and telling the operator to go and check
         * the shim on staging sends them to the wrong machine.
         */
        $this->salt->respondTo(StepName::TreeScan, false, payload: [
            'error' => 'The local salt CLI refused to run (exit 64), so nothing was sent to any minion: '
                ."PermissionError: [Errno 13] Permission denied: '/var/www/.salt'",
        ]);

        $hint = $this->actingAs($this->admin())
            ->getJson(route('api.import.show', ['fresh' => 1]))
            ->assertStatus(422)
            ->json('hint');

        $this->assertStringContainsString('failed on the portal host', $hint);
        $this->assertStringNotContainsString('tree-scan', $hint);
    }

    #[Test]
    public function salt_async_never_starting_also_points_at_the_portal_host(): void
    {
        // `salt --async` itself never got as far as scheduling anything — a local
        // CLI failure, same as the synchronous case, just caught before a JID
        // exists to poll.
        $this->salt->asyncStartFailure = new SaltAsyncStartFailed(
            "Could not start [/usr/bin/salt] asynchronously: PermissionError: [Errno 13] Permission denied: '/var/www/.salt'",
        );

        $hint = $this->actingAs($this->admin())
            ->getJson(route('api.import.show', ['fresh' => 1]))
            ->assertStatus(422)
            ->json('hint');

        $this->assertStringContainsString('failed on the portal host', $hint);
    }

    #[Test]
    public function a_manual_paste_builds_the_same_plan_a_scan_would_and_never_calls_salt(): void
    {
        $manager = $this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]);

        $payload = [
            'root' => rtrim((string) config('mwdeploy.paths.staging'), '/'),
            'versions' => ['1.45'],
            'entries' => [$this->coreEntry('1.45'), $this->echoEntry('1.45', 'REL1_45')],
            'warnings' => [],
        ];

        $response = $this->actingAs($manager)->postJson(route('api.import.manual'), [
            'payload' => json_encode($payload),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('plan.root', $payload['root']);

        // Nothing was sent to Salt at all — the whole point of the manual path.
        $this->assertSame([], $this->salt->stepSequence());

        $keys = $response->json('plan.recommended_keys');
        $this->assertNotEmpty($keys);

        $this->actingAs($manager)
            ->postJson(route('api.import.store'), ['keys' => $keys])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(2, Repository::query()->count()); // mediawiki, Echo
    }

    #[Test]
    public function a_manual_paste_rejects_invalid_json_with_a_hint(): void
    {
        $manager = $this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]);

        $response = $this->actingAs($manager)->postJson(route('api.import.manual'), [
            'payload' => 'not json at all',
            'root' => '/srv/mediawiki-staging',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
        $response->assertJsonStructure(['error', 'hint']);
    }

    #[Test]
    public function a_manual_paste_without_a_root_anywhere_is_rejected(): void
    {
        $manager = $this->userWithPermissions([Permissions::REPOSITORIES_MANAGE]);

        $response = $this->actingAs($manager)->postJson(route('api.import.manual'), [
            'payload' => json_encode(['entries' => [$this->coreEntry('1.45')]]),
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('ok', false);
    }

    #[Test]
    public function the_manual_import_endpoint_is_behind_repositories_manage(): void
    {
        $reader = $this->userWithPermissions([Permissions::DEPLOY_EXTENSION]);

        $this->actingAs($reader)->postJson(route('api.import.manual'), [
            'payload' => json_encode(['entries' => []]),
        ])->assertForbidden();
    }

    #[Test]
    public function the_artisan_command_is_a_dry_run_unless_told_otherwise(): void
    {
        $this->respondWithScan();
        $this->admin();

        $this->artisan('mwdeploy:import-tree')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, Repository::query()->count());

        $this->artisan('mwdeploy:import-tree --apply --fresh')->assertSuccessful();

        $this->assertSame(4, Repository::query()->count());
        $this->assertSame(2, MediaWikiVersion::query()->count());
    }

    #[Test]
    public function the_artisan_command_refuses_to_guess_who_to_attribute_an_import_to(): void
    {
        $this->respondWithScan();

        $this->admin();
        $this->userWithPermissions([Permissions::DEPLOY_EXTENSION]);

        $this->artisan('mwdeploy:import-tree --apply')->assertFailed();

        $this->assertSame(0, Repository::query()->count());
    }

    /**
     * Queue a tree-scan payload shaped exactly like the shim's, defaulting to a
     * small two-version farm.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function respondWithScan(array $overrides = []): void
    {
        $payload = [
            'root' => rtrim((string) config('mwdeploy.paths.staging'), '/'),
            'versions' => ['1.45', '1.46'],
            'entries' => [
                $this->coreEntry('1.45'),
                $this->echoEntry('1.45', 'REL1_45'),
                $this->entry('extension', 'Thanks', 'versions/1.45/extensions/Thanks', '1.45', 'REL1_45'),
                $this->entry('skin', 'Vector', 'versions/1.45/skins/Vector', '1.45', 'REL1_45'),
                $this->coreEntry('1.46'),
                $this->echoEntry('1.46', 'REL1_46'),
            ],
            'counts' => ['core' => 2, 'extension' => 3, 'skin' => 1],
            'warnings' => [],
            'shim_version' => '2.1.0',
            ...$overrides,
        ];

        $this->salt->alwaysRespondTo(StepName::TreeScan, true, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function coreEntry(string $version): array
    {
        return [
            ...$this->entry('core', 'mediawiki', 'versions/'.$version, $version, 'REL'.str_replace('.', '_', $version)),
            'core_version' => $version.'.0',
            'git' => [
                'url' => 'https://github.com/wikimedia/mediawiki.git',
                'ref_type' => 'branch',
                'ref' => 'REL'.str_replace('.', '_', $version),
                'commit' => str_repeat('a', 40),
                'branch' => 'REL'.str_replace('.', '_', $version),
                'default_branch' => 'master',
                'upstream' => 'origin/REL'.str_replace('.', '_', $version),
                'has_submodules' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function echoEntry(string $version, string $ref): array
    {
        return [
            ...$this->entry('extension', 'Echo', 'versions/'.$version.'/extensions/Echo', $version, $ref),
            'meta' => [
                'manifest' => 'extension.json',
                'name' => 'Notifications',
                'version' => '1.45',
                'license-name' => 'MIT',
                'requires_mediawiki' => '>= 1.43.0',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $kind, string $name, string $path, ?string $version, string $ref): array
    {
        $slug = $kind === 'skin' ? 'skins' : 'extensions';

        return [
            'kind' => $kind,
            'name' => $name,
            'path' => $path,
            'version' => $version,
            'is_git' => true,
            'git' => [
                'url' => 'https://github.com/wikioasis/mediawiki-'.$slug.'-'.$name.'.git',
                'ref_type' => 'branch',
                'ref' => $ref,
                'commit' => str_repeat('b', 40),
                'branch' => $ref,
                'default_branch' => 'master',
                'upstream' => 'origin/'.$ref,
                'has_submodules' => false,
            ],
        ];
    }

    private function plan(): ImportPlan
    {
        return app(ImportPlanner::class)->plan(app(TreeScanner::class)->scan(fresh: true));
    }
}
