<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RepositoryType;
use App\Support\PathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PathResolverTest extends TestCase
{
    private PathResolver $paths;

    protected function setUp(): void
    {
        $this->paths = new PathResolver;
    }

    #[Test]
    public function it_puts_a_core_version_in_its_version_directory(): void
    {
        $this->assertSame('versions/1.46', $this->paths->relativePath(RepositoryType::Core, 'mediawiki', '1.46'));
    }

    #[Test]
    public function it_nests_extensions_and_skins_under_the_core_version(): void
    {
        $this->assertSame(
            'versions/1.45/extensions/Echo',
            $this->paths->relativePath(RepositoryType::Extension, 'Echo', '1.45'),
        );

        $this->assertSame(
            'versions/1.45/skins/Vector',
            $this->paths->relativePath(RepositoryType::Skin, 'Vector', '1.45'),
        );
    }

    #[Test]
    public function an_unversioned_extension_lands_at_the_top_level(): void
    {
        $this->assertSame('extensions/Echo', $this->paths->relativePath(RepositoryType::Extension, 'Echo', null));
    }

    #[Test]
    public function config_always_lands_at_config(): void
    {
        $this->assertSame('config', $this->paths->relativePath(RepositoryType::Config, 'mw-config', '1.45'));
    }

    #[Test]
    public function it_refuses_to_let_a_name_climb_out_of_the_tree(): void
    {
        $path = $this->paths->relativePath(RepositoryType::Extension, '../../etc/cron.d/evil', '1.45');

        $this->assertStringNotContainsString('..', $path);
        $this->assertSame('versions/1.45/extensions/etccron.devil', $path);
    }

    #[Test]
    public function it_strips_shell_metacharacters_from_names(): void
    {
        $this->assertSame(
            'versions/1.45/extensions/Echorm-rf',
            $this->paths->relativePath(RepositoryType::Extension, 'Echo; rm -rf /', '1.45'),
        );
    }

    #[Test]
    public function it_sanitises_the_core_version_too(): void
    {
        $path = $this->paths->relativePath(RepositoryType::Core, 'mediawiki', '../1.45/../../etc/passwd');

        $this->assertStringNotContainsString('..', $path);
        $this->assertSame('versions/1.45.etcpasswd', $path);
    }
}
