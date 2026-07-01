<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;

class ApiContractInstallCommand extends Command
{
    protected $signature   = 'api:contract:install';
    protected $description = 'Install API contract git hooks, snapshot directory, and git configuration';

    public function handle(): int
    {
        $this->installHook('pre-push');
        $this->installHook('pre-commit');
        $this->createSnapshotDir();
        $this->configureGitHooksPath();

        $this->newLine();
        $this->info('API contract system installed.');
        $this->newLine();
        $this->line('Hooks installed:');
        $this->line('  <comment>pre-commit</comment>  warns about API routes with no snapshot coverage');
        $this->line('  <comment>pre-push</comment>    blocks push if existing contract snapshots are broken');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. <comment>php artisan api:contract:generate --prefix=api/v1</comment>');
        $this->line('  2. Fill in auth headers in the generated test file');
        $this->line('  3. <comment>php artisan api:contract:update</comment>');

        return self::SUCCESS;
    }

    private function installHook(string $hookName): void
    {
        $hooksDir = base_path(config('api-contract.hooks_dir', '.githooks'));

        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $hookPath  = $hooksDir . '/' . $hookName;
        $stubPath  = __DIR__ . '/../../stubs/' . $hookName;

        if (!file_exists($stubPath)) {
            $this->warn("  Stub not found for {$hookName} — skipping.");
            return;
        }

        $stub = file_get_contents($stubPath);

        $testPath  = config('api-contract.test_path', 'tests/Feature/ApiContractTest.php');
        $testFlags = config('api-contract.test_flags', '--no-coverage');
        $updateEnv = config('api-contract.update_env', 'API_CONTRACT_UPDATE');
        $prefix    = config('api-contract.route_prefix', 'api');

        $stub = str_replace(
            ['{{TEST_PATH}}', '{{TEST_FLAGS}}', '{{UPDATE_ENV}}', '{{ROUTE_PREFIX}}'],
            [$testPath, $testFlags, $updateEnv, $prefix],
            $stub
        );

        file_put_contents($hookPath, $stub);
        chmod($hookPath, 0755);

        $this->line("  ✔ <comment>{$hookName}</comment> → {$hookPath}");
    }

    private function createSnapshotDir(): void
    {
        $dir = base_path(config('api-contract.snapshot_dir', 'tests/snapshots/api'));

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/.gitkeep', '');
            $this->line("  ✔ Snapshot directory created: <comment>{$dir}</comment>");
        } else {
            $this->line("  ✔ Snapshot directory: <comment>{$dir}</comment>");
        }
    }

    private function configureGitHooksPath(): void
    {
        $hooksDir = config('api-contract.hooks_dir', '.githooks');
        exec("git config core.hooksPath {$hooksDir}", result_code: $code);

        if ($code === 0) {
            $this->line("  ✔ git config <comment>core.hooksPath = {$hooksDir}</comment>");
        } else {
            $this->warn("  Could not set core.hooksPath automatically. Run:");
            $this->line("    git config core.hooksPath {$hooksDir}");
        }
    }
}
