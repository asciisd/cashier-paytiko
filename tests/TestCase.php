<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Tests;

use Asciisd\CashierPaytiko\CashierPaytikoServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            CashierPaytikoServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('cashier-paytiko.merchant_secret_key', 'test_secret_key');
        config()->set('cashier-paytiko.core_url', 'https://test.paytiko.com');
        config()->set('cashier-paytiko.webhook_url', 'https://example.com/webhook');
        config()->set('cashier-paytiko.success_redirect_url', 'https://example.com/success');
        config()->set('cashier-paytiko.failed_redirect_url', 'https://example.com/failed');
    }
}
