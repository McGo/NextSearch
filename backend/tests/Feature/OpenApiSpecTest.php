<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps docs/api/openapi.yaml and the routes it describes in step.
 *
 * The file is a contract other people build against, and nothing about adding a
 * route forces anyone to touch it — the drift would show up in somebody else's
 * client, months later. So the two are compared here: every route under
 * /api/v1 has to appear in the specification, and every operation in the
 * specification has to exist as a route.
 *
 * Parameter names are not compared. The route calls it `{tagKey}` and the
 * specification `{tag}`; that is a naming difference, not a contract.
 */
class OpenApiSpecTest extends TestCase
{
    private const PREFIX = 'api/v1';

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $path = dirname(base_path()).'/docs/api/openapi.yaml';

        if (! is_file($path)) {
            $this->markTestSkipped('The specification is not in this checkout.');
        }

        return Yaml::parseFile($path);
    }

    /**
     * "GET /documents/{}" for both sides of the comparison.
     *
     * @return list<string>
     */
    private function specOperations(): array
    {
        $operations = [];

        foreach ($this->spec()['paths'] as $path => $item) {
            foreach ($item as $method => $_operation) {
                if (in_array(strtoupper($method), self::METHODS, true)) {
                    $operations[] = strtoupper($method).' '.$this->anonymise($path);
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function routeOperations(): array
    {
        $operations = [];

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! str_starts_with($route->uri(), self::PREFIX.'/')) {
                continue;
            }

            $path = substr($route->uri(), strlen(self::PREFIX));

            foreach ($route->methods() as $method) {
                // HEAD comes along with every GET and is not worth a line in a
                // specification.
                if (in_array($method, self::METHODS, true)) {
                    $operations[] = $method.' '.$this->anonymise($path);
                }
            }
        }

        sort($operations);

        return array_values(array_unique($operations));
    }

    private function anonymise(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;
    }

    #[Test]
    public function every_route_is_described(): void
    {
        $missing = array_diff($this->routeOperations(), $this->specOperations());

        $this->assertSame([], array_values($missing), sprintf(
            "These routes are missing from docs/api/openapi.yaml:\n  %s",
            implode("\n  ", $missing),
        ));
    }

    #[Test]
    public function nothing_is_described_that_does_not_exist(): void
    {
        $extra = array_diff($this->specOperations(), $this->routeOperations());

        $this->assertSame([], array_values($extra), sprintf(
            "docs/api/openapi.yaml describes operations that no route answers:\n  %s",
            implode("\n  ", $extra),
        ));
    }

    #[Test]
    public function the_specification_points_at_the_right_prefix(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertStringEndsWith('/'.self::PREFIX, (string) $spec['servers'][0]['url']);
    }

    #[Test]
    public function every_operation_carries_a_summary_and_an_id(): void
    {
        $ids = [];

        foreach ($this->spec()['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array(strtoupper($method), self::METHODS, true)) {
                    continue;
                }

                $where = strtoupper($method).' '.$path;

                $this->assertArrayHasKey('summary', $operation, $where);
                $this->assertArrayHasKey('operationId', $operation, $where);

                $ids[] = $operation['operationId'];
            }
        }

        // A duplicate operationId breaks the client generators that read this
        // file, and nothing else would notice.
        $this->assertSame(count($ids), count(array_unique($ids)), 'Duplicate operationId.');
    }
}
