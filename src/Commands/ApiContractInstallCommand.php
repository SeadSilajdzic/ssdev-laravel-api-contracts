<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;

class ApiContractInstallCommand extends Command
{
    protected $signature   = 'api:contract:install
                            {--ci= : Generate a CI workflow that enforces contracts on PRs (currently supports: github, bitbucket, gitlab)}';
    protected $description = 'Install API contract git hooks, snapshot directory, and git configuration';

    public function handle(): int
    {
        $this->installHook('pre-push');
        $this->installHook('pre-commit');
        $this->createSnapshotDir();
        $this->configureGitHooksPath();
        $this->offerTestDatabaseSetup();
        $this->installCiWorkflow();

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

        if ($this->option('ci') === null) {
            $this->newLine();
            $this->line('Tip: local hooks are bypassable with --no-verify and not shared via git.');
            $this->line('  Run with <comment>--ci=github</comment>, <comment>--ci=bitbucket</comment>, or <comment>--ci=gitlab</comment> to also generate a CI workflow.');
        }

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

    /**
     * Generated contract tests use RefreshDatabase, which fails on the first
     * migration if phpunit.xml has no test database configured. Detect that
     * and offer to fix it — never touched without an explicit yes.
     */
    private function offerTestDatabaseSetup(): void
    {
        $path = base_path('phpunit.xml');

        if (!file_exists($path)) {
            $this->warn('  phpunit.xml not found — skipping test database check.');
            return;
        }

        $content = file_get_contents($path);

        if (preg_match('/^\s*<env\s+name="DB_CONNECTION"/mi', $content)) {
            $this->line('  ✔ Test database already configured in phpunit.xml.');
            return;
        }

        $hasCommentedConnection = preg_match('/<!--\s*<env\s+name="DB_CONNECTION"[^>]*\/>\s*-->/i', $content);
        $hasCommentedDatabase   = preg_match('/<!--\s*<env\s+name="DB_DATABASE"[^>]*\/>\s*-->/i', $content);

        $confirmed = $this->confirm(
            '  No test database configured in phpunit.xml. Set up an in-memory SQLite database for tests now?',
            false
        );

        if (!$confirmed) {
            $this->line('  Skipped — see README "Test database setup" to configure this later.');
            return;
        }

        if ($hasCommentedConnection && $hasCommentedDatabase) {
            $content = preg_replace('/<!--\s*(<env\s+name="DB_CONNECTION"[^>]*\/>)\s*-->/i', '$1', $content);
            $content = preg_replace('/<!--\s*(<env\s+name="DB_DATABASE"[^>]*\/>)\s*-->/i', '$1', $content);
            file_put_contents($path, $content);
            $this->line('  ✔ Uncommented DB_CONNECTION / DB_DATABASE in phpunit.xml (sqlite, in-memory).');
            return;
        }

        if (!str_contains($content, '</php>')) {
            $this->warn('  Could not find a <php> section in phpunit.xml — add these manually:');
            $this->line('    <env name="DB_CONNECTION" value="sqlite"/>');
            $this->line('    <env name="DB_DATABASE" value=":memory:"/>');
            return;
        }

        $content = str_replace(
            '</php>',
            "    <env name=\"DB_CONNECTION\" value=\"sqlite\"/>\n        <env name=\"DB_DATABASE\" value=\":memory:\"/>\n    </php>",
            $content
        );

        file_put_contents($path, $content);
        $this->line('  ✔ Added sqlite in-memory test database to phpunit.xml.');
    }

    /**
     * core.hooksPath is local-only and bypassable with --no-verify, so it's
     * an honor system, not real enforcement. --ci=<provider> generates a
     * workflow that runs the same test suite server-side on every PR.
     */
    private function installCiWorkflow(): void
    {
        $ci = $this->option('ci');

        if ($ci === null) {
            return;
        }

        $providers = [
            'github'    => ['stub' => 'github-workflow.yml', 'path' => '.github/workflows/api-contract.yml'],
            'bitbucket' => ['stub' => 'bitbucket-pipelines.yml', 'path' => 'bitbucket-pipelines.yml'],
            'gitlab'    => ['stub' => 'gitlab-ci.yml', 'path' => '.gitlab-ci.yml'],
        ];

        if (!isset($providers[$ci])) {
            $supported = implode(', ', array_keys($providers));
            $this->warn("  Unsupported --ci value: '{$ci}' — currently supported: {$supported}.");
            return;
        }

        $targetPath = base_path($providers[$ci]['path']);

        if (file_exists($targetPath)) {
            $overwrite = $this->confirm(
                "  {$targetPath} already exists. Overwrite it?",
                false
            );

            if (!$overwrite) {
                $this->line('  Skipped — existing workflow file left untouched.');
                return;
            }
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = file_get_contents(__DIR__ . '/../../stubs/' . $providers[$ci]['stub']);

        $testPath  = config('api-contract.test_path', 'tests/Feature/ApiContractTest.php');
        $testFlags = config('api-contract.test_flags', '--no-coverage');

        $stub = str_replace(
            ['{{TEST_PATH}}', '{{TEST_FLAGS}}'],
            [$testPath, $testFlags],
            $stub
        );

        file_put_contents($targetPath, $stub);

        $this->line("  ✔ CI workflow ({$ci}) → {$targetPath}");
    }
}
