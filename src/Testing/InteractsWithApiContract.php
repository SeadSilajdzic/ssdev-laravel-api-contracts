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
            $this->warnAboutEmptyArrays($name, $shape);
            ApiContractSnapshot::save($name, $shape);
            return;
        }

        $snapshot   = ApiContractSnapshot::load($name);
        $violations = ApiContractSnapshot::compare($shape, $snapshot);

        if (empty($violations)) {
            $this->assertTrue(true);
            return;
        }

        $strict    = (bool) config('api-contract.strict', false);
        $breaking  = ApiContractSnapshot::hasBreakingViolations($violations, $strict);
        $formatted = ApiContractSnapshot::formatViolations($violations);
        $command   = 'php artisan api:contract:update';

        if (!$breaking) {
            fwrite(STDOUT, "\n  [{$name}] API contract changed (new fields):\n{$formatted}\n");
            $this->assertTrue(true);
            return;
        }

        $this->fail(
            "\n\n  [{$name}] BREAKING API contract violation:\n{$formatted}\n\n  → Run: {$command}  (to accept intentional changes)\n"
        );
    }

    /**
     * Warn (without failing) when the response contains empty arrays —
     * their element shape can't be captured, so it won't be validated
     * until a future snapshot update sees a non-empty response there.
     */
    private function warnAboutEmptyArrays(string $name, mixed $shape): void
    {
        $emptyPaths = ApiContractSnapshot::findEmptyArrayPaths($shape);

        if (empty($emptyPaths)) {
            return;
        }

        $list = implode(', ', $emptyPaths);
        fwrite(STDOUT, "\n  [{$name}] Warning: empty array(s) at: {$list} — item shape not captured, will not be validated until a non-empty response is snapshotted.\n");
    }
}
