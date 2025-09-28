<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko;

use Asciisd\CashierPaytiko\Http\Controllers\PaytikoWebhookController;
use Asciisd\CashierPaytiko\Services\PaytikoHostedPageService;
use Asciisd\CashierPaytiko\Services\PaytikoSignatureService;
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CashierPaytikoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cashier-paytiko.php',
            'cashier-paytiko'
        );

        $this->app->bind(PaytikoSignatureService::class, function () {
            return new PaytikoSignatureService(
                config('cashier-paytiko.merchant_secret_key')
            );
        });

        $this->app->bind(PaytikoHostedPageService::class, function ($app) {
            return new PaytikoHostedPageService(
                $app->make(Client::class),
                $app->make(PaytikoSignatureService::class),
                config('cashier-paytiko.core_url'),
                config('cashier-paytiko.merchant_secret_key')
            );
        });

        $this->app->bind(PaytikoWebhookResyncService::class, function ($app) {
            return new PaytikoWebhookResyncService(
                $app->make(Client::class),
                $app->make(PaytikoSignatureService::class),
                config('cashier-paytiko.core_url'),
                config('cashier-paytiko.merchant_secret_key')
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/cashier-paytiko.php' => config_path('cashier-paytiko.php'),
        ], 'cashier-paytiko-config');

        $this->registerRoutes();
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => 'api/webhooks',
            'middleware' => ['api'],
        ], function () {
            Route::post('paytiko', [PaytikoWebhookController::class, 'handle'])
                ->name('paytiko.webhook');
            
            // Webhook resync endpoints
            Route::post('paytiko/resync', [PaytikoWebhookController::class, 'resyncWebhooks'])
                ->name('paytiko.webhook.resync');
            
            Route::post('paytiko/resync-by-date', [PaytikoWebhookController::class, 'resyncWebhooksByDateRange'])
                ->name('paytiko.webhook.resync-by-date');
            
            Route::get('paytiko/resync-status/{resyncId}', [PaytikoWebhookController::class, 'getResyncStatus'])
                ->name('paytiko.webhook.resync-status');
            
            Route::post('paytiko/process-resynced', [PaytikoWebhookController::class, 'processResyncedWebhook'])
                ->name('paytiko.webhook.process-resynced');
        });
    }
}
