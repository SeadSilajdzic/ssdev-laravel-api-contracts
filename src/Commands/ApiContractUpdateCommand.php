<?php

namespace Ssdev\ApiContracts\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ApiContractUpdateCommand extends Command
{
    protected $signature   = 'api:contract:update';
    protected $description = 'Regenerate API contract snapshots from current responses (run after intentional changes)';

    public function handle(): int
    {
        $this->info('Updating API contract snapshots...');
        $this->newLine();

        $testPath  = config('api-contract.test_path', 'tests/Feature/ApiContractTest.php');
        $testFlags = config('api-contract.test_flags', '--no-coverage');
        $updateEnv = config('api-contract.update_env', 'API_CONTRACT_UPDATE');

        $command = trim("php artisan test {$testPath} {$testFlags}");

        $process = Process::fromShellCommandline($command, base_path());
        $process->setEnv(array_merge($_ENV, [$updateEnv => '1']));
        $process->setTimeout(120);
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        if ($process->isSuccessful()) {
            $this->newLine();
            $this->info('Snapshots updated. Review the diff and commit:');
            $snapshotDir = config('api-contract.snapshot_dir', 'tests/snapshots/api');
            $this->line("  git diff {$snapshotDir}/");
            $this->line("  git add {$snapshotDir}/");
            $this->line("  git commit -m 'contract: update API response snapshots'");
            return self::SUCCESS;
        }

        $this->error('Snapshot update failed — check the test output above.');
        return self::FAILURE;
    }
}
