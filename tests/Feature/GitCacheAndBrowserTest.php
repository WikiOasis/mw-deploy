<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StepName;
use App\Models\GitFileCacheEntry;
use App\Models\GitRefCache;
use App\Models\MediaWikiVersion;
use App\Models\RepositoryVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The persistent ref cache (CachedGitRefProvider), the file-at-commit browser
 * (CachedGitFileBrowser + RepoBrowserController), and the two Artisan commands
 * that keep both tidy.
 */
final class GitCacheAndBrowserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MediaWikiVersion $version;

    private RepositoryVersion $echo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->version = $this->version('1.45');
        $this->echo = $this->extension('Echo', $this->version, 'master');
    }

    #[Test]
    public function refs_are_populated_live_on_the_first_view_and_written_to_the_cache(): void
    {
        $salt = $this->fakeSalt();
        $salt->respondTo(StepName::GitRefs, true, payload: [
            'refs' => [['value' => 'master', 'subject' => 'first', 'author' => 'A', 'date' => '2026-01-01']],
        ]);
        $salt->respondTo(StepName::GitRefs, true, payload: [
            'refs' => [['value' => 'abc123', 'subject' => 'second', 'author' => 'A', 'date' => '2026-01-02']],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.refs', $this->echo))
            ->assertOk();

        $response->assertJsonPath('branches.0.value', 'master');
        $response->assertJsonPath('commits.0.value', 'abc123');
        $this->assertNotNull($response->json('fetched_at'));

        $this->assertSame(2, GitRefCache::query()->where('repository_version_id', $this->echo->getKey())->count());
        // GitRefs was called once for branches, once for commits — no third call
        // for a second view, because the second view is served from the cache.
        $this->assertCount(2, $salt->callsFor(StepName::GitRefs));
    }

    #[Test]
    public function a_second_view_is_served_from_the_cache_without_another_salt_call(): void
    {
        $salt = $this->fakeSalt();
        $salt->alwaysRespondTo(StepName::GitRefs, true, ['refs' => [['value' => 'master']]]);

        $this->actingAs($this->admin)->getJson(route('api.checkouts.refs', $this->echo))->assertOk();
        $this->actingAs($this->admin)->getJson(route('api.checkouts.refs', $this->echo))->assertOk();

        // Two kinds (branches, commits) each only fetched once across both views.
        $this->assertCount(2, $salt->callsFor(StepName::GitRefs));
    }

    #[Test]
    public function fetch_bypasses_the_cache_and_rewrites_it(): void
    {
        $salt = $this->fakeSalt();
        $salt->alwaysRespondTo(StepName::GitRefs, true, ['refs' => [['value' => 'master']]]);
        $salt->alwaysRespondTo(StepName::GitFetch, true);

        $this->actingAs($this->admin)->getJson(route('api.checkouts.refs', $this->echo))->assertOk();

        $this->actingAs($this->admin)
            ->postJson(route('api.checkouts.refs.fetch', $this->echo))
            ->assertOk();

        $salt->assertRan(StepName::GitFetch);
        // refresh() re-lists both kinds live: branches + commits, on top of the
        // one branches + one commits call the first view already made.
        $this->assertCount(4, $salt->callsFor(StepName::GitRefs));
    }

    #[Test]
    public function the_rebuild_command_refreshes_every_present_checkout(): void
    {
        $this->core($this->version);
        $salt = $this->fakeSalt();
        $salt->alwaysRespondTo(StepName::GitFetch, true);
        $salt->alwaysRespondTo(StepName::GitRefs, true, ['refs' => []]);

        $this->artisan('mwdeploy:rebuild-git-cache')->assertSuccessful();

        // Two present checkouts (Echo + core), each writes a branches row and a
        // commits row.
        $salt->assertRan(StepName::GitFetch);
        $this->assertSame(4, GitRefCache::query()->count());
    }

    #[Test]
    public function tree_and_blob_are_cached_by_resolved_sha(): void
    {
        $sha = str_repeat('a', 40);
        $salt = $this->fakeSalt();
        $salt->alwaysRespondTo(StepName::GitResolve, true, ['sha' => $sha]);
        $salt->alwaysRespondTo(StepName::GitLsTree, true, [
            'entries' => [['name' => 'extension.json', 'type' => 'blob', 'mode' => '100644', 'size' => 42]],
        ]);
        $salt->alwaysRespondTo(StepName::GitShowBlob, true, [
            'content' => '{"name": "Echo"}', 'size' => 17, 'truncated' => false, 'binary' => false,
        ]);

        $tree = $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.tree', $this->echo).'?ref=master&path=')
            ->assertOk();

        $tree->assertJsonPath('sha', $sha);
        $tree->assertJsonPath('entries.0.name', 'extension.json');

        $blob = $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.blob', $this->echo).'?ref=master&path=extension.json')
            ->assertOk();

        $blob->assertJsonPath('content', '{"name": "Echo"}');

        // A second tree call for the same ref/path resolves again (ref → SHA is
        // not itself cached across requests) but must not hit ls-tree again,
        // since the resolved SHA + path pair is already in the file cache.
        $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.tree', $this->echo).'?ref=master&path=')
            ->assertOk();

        $this->assertCount(1, $salt->callsFor(StepName::GitLsTree));

        $this->assertSame(
            2,
            GitFileCacheEntry::query()->where('repository_version_id', $this->echo->getKey())->count(),
        );
    }

    #[Test]
    public function resolving_an_already_full_sha_skips_the_round_trip(): void
    {
        $salt = $this->fakeSalt();
        $sha = str_repeat('b', 40);
        $salt->alwaysRespondTo(StepName::GitLsTree, true, ['entries' => []]);

        $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.tree', $this->echo)."?ref={$sha}&path=")
            ->assertOk()
            ->assertJsonPath('sha', $sha);

        $salt->assertNeverRan(StepName::GitResolve);
    }

    #[Test]
    public function a_ref_that_cannot_be_resolved_is_a_404_not_an_empty_tree(): void
    {
        $salt = $this->fakeSalt();
        $salt->alwaysRespondTo(StepName::GitResolve, false);

        $this->actingAs($this->admin)
            ->getJson(route('api.checkouts.tree', $this->echo).'?ref=no-such-branch&path=')
            ->assertNotFound();
    }

    #[Test]
    public function the_prune_command_deletes_only_entries_past_the_ttl(): void
    {
        GitFileCacheEntry::factory()->for($this->echo, 'repositoryVersion')->create([
            'last_accessed_at' => now()->subHours(48),
        ]);
        $fresh = GitFileCacheEntry::factory()->for($this->echo, 'repositoryVersion')->create([
            'last_accessed_at' => now(),
        ]);

        $this->artisan('mwdeploy:prune-git-file-cache')->assertSuccessful();

        $this->assertSame(1, GitFileCacheEntry::query()->count());
        $this->assertTrue(GitFileCacheEntry::query()->whereKey($fresh->getKey())->exists());
    }
}
