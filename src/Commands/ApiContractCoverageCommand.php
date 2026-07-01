<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;

class ApiContractCoverageCommand extends Command
{
    protected $signature = 'api:contract:coverage
                            {--prefix=api : URI prefix to scan}
                            {--fail : Exit with non-zero code if uncovered routes exist}';

    protected $description = 'Report API routes that have no contract snapshot coverage';

    public function handle(): int
    {
        $prefix   = rtrim($this->option('prefix'), '/');
        $manifest = $this->loadManifest();

        if (empty($manifest)) {
            $this->warn('No manifest found. Run `php artisan api:contract:generate` first.');
            return self::SUCCESS;
        }

        $coveredUris = array_keys($manifest['routes'] ?? []);
        $uncovered   = [];

        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();

            if (!str_starts_with($uri, $prefix)) {
                continue;
            }

            $methods = array_diff($route->methods(), ['HEAD', 'OPTIONS']);

            foreach ($methods as $method) {
                $key = strtoupper($method) . ' /' . $uri;
                if (!in_array($key, $coveredUris, true)) {
                    $uncovered[] = $key;
                }
            }
        }

        if (empty($uncovered)) {
            $this->info('All API routes have contract coverage.');
            return self::SUCCESS;
        }

        $this->warn(count($uncovered) . ' route(s) have no contract snapshot:');
        foreach ($uncovered as $uri) {
            $this->line("  <comment>→</comment> {$uri}");
        }
        $this->newLine();
        $this->line('Run <comment>php artisan api:contract:generate --merge</comment> to add test stubs.');

        return $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }

    private function loadManifest(): array
    {
        $dir  = base_path(config('api-contract.snapshot_dir', 'tests/snapshots/api'));
        $path = $dir . '/.manifest.json';

        return file_exists($path)
            ? (json_decode(file_get_contents($path), true) ?? [])
            : [];
    }
}
