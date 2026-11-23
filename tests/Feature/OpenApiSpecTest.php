<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Ledger\Domain\Shared\Currency;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/openapi.yaml honest: the documented operations are exactly
 * the routes that exist, and shared enums match the code.
 */
final class OpenApiSpecTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        $spec = Yaml::parseFile(__DIR__.'/../../docs/openapi.yaml');
        self::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        return $spec;
    }

    /**
     * @return array<string, mixed>
     */
    private static function section(mixed $node, string $key): array
    {
        self::assertIsArray($node);
        self::assertArrayHasKey($key, $node);
        self::assertIsArray($node[$key]);

        /** @var array<string, mixed> $section */
        $section = $node[$key];

        return $section;
    }

    #[Test]
    public function it_is_an_openapi_3_1_document(): void
    {
        self::assertSame('3.1.0', self::spec()['openapi']);
    }

    #[Test]
    public function every_api_route_is_documented_and_nothing_else(): void
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $path = '/'.substr($route->uri(), strlen('api/v1/'));

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = strtolower($method).' '.$path;
            }
        }

        $documented = [];

        foreach (self::section(self::spec(), 'paths') as $path => $operations) {
            self::assertIsArray($operations);

            foreach (array_keys($operations) as $method) {
                $documented[] = $method.' '.$path;
            }
        }

        sort($routes);
        sort($documented);

        self::assertSame($routes, $documented);
    }

    #[Test]
    public function every_operation_documents_its_success_response(): void
    {
        foreach (self::section(self::spec(), 'paths') as $path => $operations) {
            self::assertIsArray($operations);

            foreach ($operations as $method => $operation) {
                $statuses = array_keys(self::section($operation, 'responses'));
                $successes = array_filter($statuses, fn (int|string $status) => (int) $status >= 200 && (int) $status < 300);

                self::assertNotEmpty($successes, sprintf('%s %s has no 2xx response.', $method, $path));
            }
        }
    }

    #[Test]
    public function the_currency_enum_matches_the_supported_currencies(): void
    {
        $schemas = self::section(self::section(self::spec(), 'components'), 'schemas');
        $currency = self::section($schemas, 'CurrencyCode');

        self::assertSame(Currency::supportedCodes(), $currency['enum']);
    }

    #[Test]
    public function every_local_reference_resolves(): void
    {
        $spec = self::spec();
        $yaml = (string) file_get_contents(__DIR__.'/../../docs/openapi.yaml');
        preg_match_all("/'#\/([^']+)'/", $yaml, $matches);

        self::assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $reference) {
            $node = $spec;

            foreach (explode('/', $reference) as $segment) {
                $node = self::section($node, $segment);
            }
        }
    }
}
