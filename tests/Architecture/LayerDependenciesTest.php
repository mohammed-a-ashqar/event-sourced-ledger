<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The hexagon, enforced: dependencies only point inwards.
 *
 *   Infrastructure / App (Laravel)  ->  Application  ->  Domain  ->  EventSourcing kernel
 */
final class LayerDependenciesTest extends TestCase
{
    private const SRC = __DIR__.'/../../src';

    /**
     * @param  list<string>  $allowed
     * @return array<string, list<string>> file => offending dependencies
     */
    private static function violations(string $layer, array $allowed): array
    {
        $violations = [];

        foreach (SourceFile::in(self::SRC.'/'.$layer) as $file) {
            $outside = $file->dependenciesOutside($allowed);

            if ($outside !== []) {
                $violations[basename($file->path)] = $outside;
            }
        }

        return $violations;
    }

    #[Test]
    public function the_event_sourcing_kernel_depends_on_nothing_but_php(): void
    {
        self::assertSame([], self::violations('EventSourcing', ['Ledger\\EventSourcing\\']));
    }

    #[Test]
    public function the_domain_depends_only_on_itself_and_the_kernel(): void
    {
        self::assertSame([], self::violations('Domain', ['Ledger\\Domain\\', 'Ledger\\EventSourcing\\']));
    }

    #[Test]
    public function the_application_layer_knows_no_framework_and_no_adapter(): void
    {
        self::assertSame([], self::violations('Application', ['Ledger\\Application\\', 'Ledger\\Domain\\', 'Ledger\\EventSourcing\\']));
    }

    #[Test]
    public function nothing_in_src_depends_on_the_laravel_app_namespace(): void
    {
        $offenders = [];

        foreach (SourceFile::in(self::SRC) as $file) {
            foreach ($file->dependencies as $dependency) {
                if (str_starts_with($dependency, 'App\\')) {
                    $offenders[] = basename($file->path).' -> '.$dependency;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    #[Test]
    public function every_php_file_declares_strict_types(): void
    {
        $missing = [];

        foreach ([self::SRC, __DIR__.'/../../app', __DIR__.'/../../database', __DIR__.'/../../routes', __DIR__.'/../../config/ledger.php'] as $path) {
            $files = is_dir($path) ? SourceFile::in($path) : [SourceFile::fromPath($path)];

            foreach ($files as $file) {
                if (! $file->declaresStrictTypes()) {
                    $missing[] = $file->path;
                }
            }
        }

        self::assertSame([], $missing);
    }

    #[Test]
    public function domain_and_application_classes_are_final_or_abstract(): void
    {
        $open = [];

        foreach ([...SourceFile::in(self::SRC.'/Domain'), ...SourceFile::in(self::SRC.'/Application')] as $file) {
            if ($file->declaresClass() && ! $file->isFinalOrAbstractClass()) {
                $open[] = basename($file->path);
            }
        }

        self::assertSame([], $open);
    }

    #[Test]
    public function the_scanner_really_detects_forbidden_imports(): void
    {
        $file = SourceFile::fromCode('Leaky.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Ledger\Domain\Account;

            use Illuminate\Support\Facades\DB;
            use Ledger\Domain\Shared\Money;

            final class Leaky
            {
                use SomeTrait;

                public function run(): void
                {
                    $f = function () use ($x) {};
                    \Illuminate\Support\Str::random();
                    new \DateTimeImmutable();
                }
            }
            PHP);

        self::assertSame(
            ['Illuminate\\Support\\Facades\\DB', 'Illuminate\\Support\\Str'],
            $file->dependenciesOutside(['Ledger\\Domain\\', 'Ledger\\EventSourcing\\']),
        );
        self::assertTrue($file->isFinalOrAbstractClass());
    }
}
