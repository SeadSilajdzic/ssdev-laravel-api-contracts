<?php

namespace Ssdev\ApiContracts;

use Illuminate\Support\ServiceProvider;
use Ssdev\ApiContracts\Commands\ApiContractCoverageCommand;
use Ssdev\ApiContracts\Commands\ApiContractGenerateCommand;
use Ssdev\ApiContracts\Commands\ApiContractInstallCommand;
use Ssdev\ApiContracts\Commands\ApiContractUpdateCommand;

class ApiContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-contract.php', 'api-contract');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/api-contract.php' => config_path('api-contract.php'),
            ], 'api-contract-config');

            $this->commands([
                ApiContractInstallCommand::class,
                ApiContractUpdateCommand::class,
                ApiContractGenerateCommand::class,
                ApiContractCoverageCommand::class,
            ]);
        }
    }
}
