<?php

namespace Finpay;

use Illuminate\Support\ServiceProvider;

class FinpayServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    protected bool $defer = false;

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config/finpay.php' => config_path('finpay.php'),
        ], 'finpay-config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/routes.php');
    }

    public function register(): void
    {
        $this->app->singleton(Services\FinpayGatewayService::class, function ($app) {
            return new Services\FinpayGatewayService();
        });

        $this->mergeConfigFrom(__DIR__ . '/config/finpay.php', 'finpay');
    }

    public function provides(): array
    {
        return [Services\FinpayGatewayService::class];
    }
}
