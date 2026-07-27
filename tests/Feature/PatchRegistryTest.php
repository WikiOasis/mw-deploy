<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StepName;
use App\Models\Patch;
use App\Models\Repository;
use App\Services\Salt\Testing\FakeSaltClient;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PatchRegistryTest extends TestCase
{
    use RefreshDatabase;

    private FakeSaltClient $salt;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mwdeploy.targets.staging' => 'staging']);

        Storage::fake('patches');

        $this->salt = $this->fakeSalt();
    }

    #[Test]
    public function managing_patches_needs_its_own_permission(): void
    {
        $deployer = $this->userWithPermissions([
            Permissions::DEPLOY_EXTENSION,
            Permissions::DEPLOY_PRODUCTION_SERVERS,
        ]);

        $this->actingAs($deployer)->get(route('patches.create'))->assertForbidden();

        // Adding a patch is arbitrary code that will run on every appserver, so
        // it is gated separately from "can deploy".
        $this->actingAs($deployer)
            ->post(route('patches.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, Patch::query()->count());
    }

    #[Test]
    public function it_stores_the_uploaded_file_and_remembers_the_target(): void
    {
        $repository = Repository::factory()->create(['name' => 'Echo']);

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->post(route('patches.store'), [
                ...$this->payload(),
                'target_repo_id' => $repository->getKey(),
                'target_path' => $repository->path,
            ])
            ->assertSessionHasNoErrors();

        $patch = Patch::query()->firstOrFail();

        // Target path lives on the patch, so an operator never retypes it.
        $this->assertSame($repository->path, $patch->target_path);
        $this->assertSame($repository->getKey(), $patch->target_repo_id);

        Storage::disk('patches')->assertExists($patch->file_path);
    }

    #[Test]
    public function it_rejects_a_target_path_that_climbs_out_of_the_tree(): void
    {
        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->post(route('patches.store'), [...$this->payload(), 'target_path' => '../../etc'])
            ->assertSessionHasErrors('target_path');

        $this->assertSame(0, Patch::query()->count());
    }

    #[Test]
    public function the_dry_run_check_records_a_clean_verdict(): void
    {
        $patch = Patch::factory()->create();

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->post(route('patches.check', $patch));

        $patch->refresh();

        $this->assertTrue($patch->last_check_ok);
        $this->assertNotNull($patch->last_checked_at);

        $command = $this->salt->callsFor(StepName::PatchApply)[0]->command->toString();

        // --check must be present, or the "does this still apply?" button would
        // actually patch staging.
        $this->assertStringContainsString("'--check'", $command);
    }

    #[Test]
    public function the_dry_run_check_records_a_stale_patch(): void
    {
        $patch = Patch::factory()->create();

        $this->salt->alwaysRespondTo(StepName::PatchApply, false);

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->post(route('patches.check', $patch));

        $patch->refresh();

        $this->assertFalse($patch->last_check_ok);
        $this->assertNotEmpty($patch->last_check_detail);
    }

    #[Test]
    public function the_patch_format_decides_which_tool_the_shim_uses(): void
    {
        $patch = Patch::factory()->create(['format' => 'git']);

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->post(route('patches.check', $patch));

        $this->assertStringContainsString(
            "'--format' 'git'",
            $this->salt->callsFor(StepName::PatchApply)[0]->command->toString(),
        );
    }

    #[Test]
    public function replacing_the_file_invalidates_the_previous_verdict(): void
    {
        $patch = Patch::factory()->create([
            'last_check_ok' => true,
            'last_checked_at' => now()->subDay(),
            'last_check_detail' => 'was fine',
        ]);

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->put(route('patches.update', $patch), [
                'name' => $patch->name,
                'target_path' => $patch->target_path,
                'format' => 'unified',
                'active' => '1',
                'patch_file' => UploadedFile::fake()->createWithContent('new.patch', "--- a\n+++ b\n"),
            ])
            ->assertSessionHasNoErrors();

        $patch->refresh();

        // A different file has not been validated against anything yet.
        $this->assertNull($patch->last_check_ok);
        $this->assertNull($patch->last_checked_at);
    }

    #[Test]
    public function editing_without_a_new_file_keeps_the_stored_one(): void
    {
        $patch = Patch::factory()->create(['description' => 'old']);
        $original = $patch->file_path;

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->put(route('patches.update', $patch), [
                'name' => $patch->name,
                'description' => 'new description',
                'target_path' => $patch->target_path,
                'format' => 'unified',
                'active' => '1',
            ])
            ->assertSessionHasNoErrors();

        $patch->refresh();

        $this->assertSame('new description', $patch->description);
        $this->assertSame($original, $patch->file_path);
    }

    #[Test]
    public function deleting_deactivates_so_past_deployments_still_resolve(): void
    {
        $patch = Patch::factory()->create();

        $this->actingAs($this->userWithPermissions([Permissions::PATCHES_MANAGE]))
            ->delete(route('patches.destroy', $patch));

        $this->assertDatabaseHas('patches', ['id' => $patch->getKey(), 'active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'name' => 'T12345 hotfix',
            'description' => 'Backport for an upstream regression.',
            'target_path' => 'versions/1.45/extensions/Echo',
            'format' => 'unified',
            'active' => '1',
            'patch_file' => UploadedFile::fake()->createWithContent(
                'hotfix.patch',
                "--- a/Echo.php\n+++ b/Echo.php\n@@ -1 +1 @@\n-old\n+new\n",
            ),
        ];
    }
}
