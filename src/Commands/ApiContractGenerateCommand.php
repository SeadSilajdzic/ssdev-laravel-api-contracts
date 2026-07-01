<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class ApiContractGenerateCommand extends Command
{
    protected $signature = 'api:contract:generate
                            {--prefix=api : URI prefix to scan (e.g. api/v1)}
                            {--output= : Output test file path (default from config)}
                            {--force : Overwrite existing test file}
                            {--merge : Only add tests for routes not already in the manifest}';

    protected $description = 'Generate contract test stubs from registered API routes';

    public function handle(): int
    {
        $prefix = rtrim($this->option('prefix'), '/');
        $output = $this->option('output') ?? config('api-contract.test_path', 'tests/Feature/ApiContractTest.php');
        $outputPath = base_path($output);

        $routes = $this->discoverRoutes($prefix);

        if (empty($routes)) {
            $this->warn("No GET routes found with prefix: {$prefix}");
            return self::FAILURE;
        }

        if (!$this->pestAvailable()) {
            $this->warn('Pest was not detected in this project.');
            $this->line('  The generated test file uses Pest syntax (uses()/it()) and will not run without it.');
            $this->line('  Install it with: <comment>composer require pestphp/pest --dev</comment>');
            $this->line('  ...or write PHPUnit tests manually — see the README "Writing tests manually" section.');
            $this->newLine();
        }

        $merge = $this->option('merge');
        $force = $this->option('force');

        if (file_exists($outputPath) && !$force && !$merge) {
            $this->warn("Test file already exists: {$output}");
            $this->line('  --merge   Add tests for new routes only');
            $this->line('  --force   Overwrite the entire file');
            return self::FAILURE;
        }

        $manifest     = $this->loadManifest();
        $existingUris = array_keys($manifest['routes'] ?? []);

        if ($merge) {
            $routes = array_filter(
                $routes,
                fn ($r) => !in_array($r['method'] . ' /' . $r['uri'], $existingUris, true)
            );

            if (empty($routes)) {
                $this->info('All routes already have test coverage — nothing to add.');
                return self::SUCCESS;
            }
        }

        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        if ($merge && file_exists($outputPath)) {
            $this->mergeIntoExistingFile($outputPath, $routes, $manifest);
        } else {
            file_put_contents($outputPath, $this->buildTestFile($routes, $prefix));
        }

        $this->saveManifest($routes, $manifest, $merge);

        $count = count($routes);
        $this->info("Generated {$count} contract test(s) → {$output}");
        $this->line('Next:');
        $this->line('  1. Fill in <comment>contractHeaders()</comment> with your auth headers');
        $this->line('  2. Replace placeholder IDs in parameterised routes');
        $this->line('  3. Run: <comment>php artisan api:contract:update</comment>');

        return self::SUCCESS;
    }

    private function pestAvailable(): bool
    {
        return function_exists('uses') && function_exists('it');
    }

    private function discoverRoutes(string $prefix): array
    {
        $routes = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (!str_starts_with($uri, $prefix)) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);

            foreach ($methods as $method) {
                $routes[] = [
                    'method'       => strtoupper($method),
                    'uri'          => $uri,
                    'name'         => $route->getName() ?? '',
                    'action'       => $route->getActionName(),
                    'has_params'   => str_contains($uri, '{'),
                    'snapshot_key' => $this->makeSnapshotKey($method, $uri),
                    'test_name'    => strtoupper($method) . ' /' . $uri,
                    'php_uri'      => $this->makePhpUri($uri),
                ];
            }
        }

        usort($routes, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

        return $routes;
    }

    private function makeSnapshotKey(string $method, string $uri): string
    {
        // GET api/v1/products/{id} → GET_api_v1_products_show
        $slug = strtoupper($method) . '_' . $uri;
        $slug = preg_replace('/\{[^}]+\}/', 'show', $slug);
        $slug = preg_replace('/[\/\-]/', '_', $slug);
        $slug = preg_replace('/[^A-Za-z0-9_]/', '', $slug);
        $slug = preg_replace('/_+/', '_', $slug);

        return trim($slug, '_');
    }

    private function makePhpUri(string $uri): string
    {
        // api/v1/products/{id} → "/api/v1/products/{$id}"
        return '/' . preg_replace('/\{([^}]+)\}/', '{\$$1}', $uri);
    }

    private function buildTestFile(array $routes, string $prefix): string
    {
        $getRoutes   = array_filter($routes, fn ($r) => $r['method'] === 'GET');
        $otherRoutes = array_filter($routes, fn ($r) => $r['method'] !== 'GET');

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = '/**';
        $lines[] = ' * API Contract Tests';
        $lines[] = ' *';
        $lines[] = ' * Auto-generated by ssdev/laravel-api-contracts.';
        $lines[] = ' * Run `php artisan api:contract:generate --merge` to add tests for new routes.';
        $lines[] = ' *';
        $lines[] = ' * Before first run:';
        $lines[] = ' *   1. Fill in contractHeaders() with your auth setup';
        $lines[] = ' *   2. Replace placeholder IDs in parameterised routes';
        $lines[] = ' *   3. Run: php artisan api:contract:update';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'use Ssdev\ApiContracts\Testing\InteractsWithApiContract;';
        $lines[] = '';
        $lines[] = 'uses(InteractsWithApiContract::class);';
        $lines[] = 'uses(Illuminate\Foundation\Testing\RefreshDatabase::class);';
        $lines[] = '';
        $lines[] = '// ---------------------------------------------------------------------------';
        $lines[] = '// Auth setup — fill this in before running';
        $lines[] = '// ---------------------------------------------------------------------------';
        $lines[] = '';
        $lines[] = 'function contractHeaders(): array';
        $lines[] = '{';
        $lines[] = '    // TODO: return authentication headers for your API';
        $lines[] = "    // Example: return ['X-API-KEY' => 'key', 'X-API-SECRET' => 'secret'];";
        $lines[] = "    return ['Accept' => 'application/json'];";
        $lines[] = '}';
        $lines[] = '';

        foreach ($getRoutes as $route) {
            $lines[] = '// ---------------------------------------------------------------------------';
            $lines[] = "// {$route['test_name']}";
            $lines[] = '// ---------------------------------------------------------------------------';
            $lines[] = '';
            $lines[] = $this->buildGetTest($route);
            $lines[] = '';
        }

        if (!empty($otherRoutes)) {
            $lines[] = '// ---------------------------------------------------------------------------';
            $lines[] = '// Non-GET routes — uncomment and implement as needed';
            $lines[] = '// ---------------------------------------------------------------------------';
            $lines[] = '';
            foreach ($otherRoutes as $route) {
                foreach ($this->buildNonGetTest($route) as $line) {
                    $lines[] = $line;
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    private function buildGetTest(array $route): string
    {
        $snapshotKey = $route['snapshot_key'];
        $testName    = $route['test_name'];
        $phpUri      = $route['php_uri'];

        if ($route['has_params']) {
            $params    = [];
            $paramVars = [];

            preg_match_all('/\{([^}]+)\}/', $route['uri'], $matches);
            foreach ($matches[1] as $param) {
                $params[]    = "    \${$param} = 1; // TODO: replace with a valid {$param}";
                $paramVars[] = $param;
            }

            $paramsCode = implode("\n", $params) . "\n";
            $uriCode    = '"' . $phpUri . '"';

            return "it('{$testName} matches contract', function () {\n{$paramsCode}    \$response = \$this->withHeaders(contractHeaders())->getJson({$uriCode});\n    \$response->assertStatus(200);\n    \$this->assertMatchesApiContract('{$snapshotKey}', \$response->json());\n});";
        }

        return "it('{$testName} matches contract', function () {\n    \$response = \$this->withHeaders(contractHeaders())->getJson('{$phpUri}');\n    \$response->assertStatus(200);\n    \$this->assertMatchesApiContract('{$snapshotKey}', \$response->json());\n});";
    }

    private function buildNonGetTest(array $route): array
    {
        $method      = strtolower($route['method']);
        $phpUri      = $route['php_uri'];
        $snapshotKey = $route['snapshot_key'];
        $testName    = $route['test_name'];
        $status      = $route['method'] === 'POST' ? 201 : 200;
        $bodyArg     = in_array($route['method'], ['POST', 'PUT', 'PATCH']) ? ", [\n//         // TODO: request body\n//     ]" : '';

        $lines = ["// it('{$testName} matches contract', function () {"];

        if ($route['has_params']) {
            preg_match_all('/\{([^}]+)\}/', $route['uri'], $matches);
            foreach ($matches[1] as $param) {
                $lines[] = "//     \${$param} = 1; // TODO: replace with a valid {$param}";
            }
        }

        $uriCode = $route['has_params'] ? "\"{$phpUri}\"" : "'{$phpUri}'";

        $lines[] = "//     \$response = \$this->withHeaders(contractHeaders())->{$method}Json({$uriCode}{$bodyArg});";
        $lines[] = "//     \$response->assertStatus({$status});";
        $lines[] = "//     \$this->assertMatchesApiContract('{$snapshotKey}', \$response->json());";
        $lines[] = '// });';

        return $lines;
    }

    private function mergeIntoExistingFile(string $outputPath, array $newRoutes, array $manifest): void
    {
        $existing = file_get_contents($outputPath);
        $append   = "\n// ---------------------------------------------------------------------------\n";
        $append  .= "// Added by api:contract:generate --merge\n";
        $append  .= "// ---------------------------------------------------------------------------\n";

        foreach (array_filter($newRoutes, fn ($r) => $r['method'] === 'GET') as $route) {
            $append .= "\n// {$route['test_name']}\n\n";
            $append .= $this->buildGetTest($route) . "\n";
        }

        file_put_contents($outputPath, rtrim($existing) . "\n" . $append);
    }

    private function manifestPath(): string
    {
        $dir = base_path(config('api-contract.snapshot_dir', 'tests/snapshots/api'));

        return $dir . '/.manifest.json';
    }

    private function loadManifest(): array
    {
        $path = $this->manifestPath();

        return file_exists($path)
            ? (json_decode(file_get_contents($path), true) ?? [])
            : [];
    }

    private function saveManifest(array $routes, array $existing, bool $merge): void
    {
        $map = $merge ? ($existing['routes'] ?? []) : [];

        foreach ($routes as $route) {
            $key       = $route['method'] . ' /' . $route['uri'];
            $map[$key] = $route['snapshot_key'];
        }

        ksort($map);

        $manifest = [
            'generated_at' => now()->toIso8601String(),
            'routes'       => $map,
        ];

        $dir = dirname($this->manifestPath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->manifestPath(),
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
