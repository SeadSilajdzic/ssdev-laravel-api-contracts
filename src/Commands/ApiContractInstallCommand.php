<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;

class ApiContractInstallCommand extends Command
{
    protected $signature   = 'api:contract:install';
    protected $description = 'Install the API contract pre-push git hook and create the snapshots directory';

    public function handle(): int
    {
        $this->installGitHook();
        $this->createSnapshotDir();
        $this->configureGitHooksPath();

        $this->newLine();
        $this->info('API contract system installed.');
        $this->line('Next: write contract tests using <comment>InteractsWithApiContract</comment>, then run:');
        $this->line('  php artisan api:contract:update');

        return self::SUCCESS;
    }

    private function installGitHook(): void
    {
        $hooksDir = base_path(config('api-contract.hooks_dir', '.githooks'));

        if (!is_dir($hooksDir)) {
            mkdir($hooksDir, 0755, true);
        }

        $hookPath = $hooksDir . '/pre-push';
        $stub     = file_get_contents($this->stubPath());

        // Inject project-specific test path and env var
        $testPath  = config('api-contract.test_path', 'tests/Feature/ApiContractTest.php');
        $testFlags = config('api-contract.test_flags', '--no-coverage');
        $updateEnv = config('api-contract.update_env', 'API_CONTRACT_UPDATE');

        $stub = str_replace(
            ['{{TEST_PATH}}', '{{TEST_FLAGS}}', '{{UPDATE_ENV}}'],
            [$testPath, $testFlags, $updateEnv],
            $stub
        );

        file_put_contents($hookPath, $stub);
        chmod($hookPath, 0755);

        $this->line("  ✔ Git hook written to <comment>{$hookPath}</comment>");
    }

    private function createSnapshotDir(): void
    {
        $dir = base_path(config('api-contract.snapshot_dir', 'tests/snapshots/api'));

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/.gitkeep', '');
            $this->line("  ✔ Snapshot directory created: <comment>{$dir}</comment>");
        } else {
            $this->line("  ✔ Snapshot directory already exists: <comment>{$dir}</comment>");
        }
    }

    private function configureGitHooksPath(): void
    {
        $hooksDir = config('api-contract.hooks_dir', '.githooks');
        exec("git config core.hooksPath {$hooksDir}", result_code: $code);

        if ($code === 0) {
            $this->line("  ✔ Git configured: <comment>core.hooksPath = {$hooksDir}</comment>");
        } else {
            $this->warn("  Could not set core.hooksPath automatically. Run manually:");
            $this->line("    git config core.hooksPath {$hooksDir}");
        }
    }

    private function stubPath(): string
    {
        return __DIR__ . '/../../stubs/pre-push';
    }
}
