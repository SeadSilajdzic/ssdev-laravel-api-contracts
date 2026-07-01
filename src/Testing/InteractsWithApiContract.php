<?php

namespace Ssdev\ApiContracts\Testing;

use Ssdev\ApiContracts\ApiContractSnapshot;

/**
 * Add to any Pest/PHPUnit test class to get assertMatchesApiContract().
 *
 * Pest usage:
 *   uses(\Ssdev\ApiContracts\Testing\InteractsWithApiContract::class);
 *
 * PHPUnit usage:
 *   use \Ssdev\ApiContracts\Testing\InteractsWithApiContract;
 */
trait InteractsWithApiContract
{
    /**
     * Assert the response matches the stored snapshot.
     *
     * On first run (no snapshot) or when API_CONTRACT_UPDATE=1, writes the snapshot.
     * On subsequent runs, compares shape and fails on BREAKING violations.
     * NEW fields (additive) are reported but do not fail the test.
     */
    public function assertMatchesApiContract(string $name, array $responseData): void
    {
        $updateEnv = config('api-contract.update_env', 'API_CONTRACT_UPDATE');
        $updating  = getenv($updateEnv) === '1';

        $shape = ApiContractSnapshot::extractShape($responseData);

        if ($updating || !ApiContractSnapshot::exists($name)) {
            ApiContractSnapshot::save($name, $shape);
            return;
        }

        $snapshot   = ApiContractSnapshot::load($name);
        $violations = ApiContractSnapshot::compare($shape, $snapshot);

        if (empty($violations)) {
            $this->assertTrue(true);
            return;
        }

        $breaking  = ApiContractSnapshot::hasBreakingViolations($violations);
        $formatted = ApiContractSnapshot::formatViolations($violations);
        $label     = $breaking ? 'BREAKING API contract violation' : 'API contract changed (new fields)';
        $command   = 'php artisan api:contract:update';

        $this->assertEmpty(
            $violations,
            "\n\n  [{$name}] {$label}:\n{$formatted}\n\n  → Run: {$command}  (to accept intentional changes)\n"
        );
    }
}
