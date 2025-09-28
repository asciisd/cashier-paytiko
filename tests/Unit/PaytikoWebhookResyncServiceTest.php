<?php

declare(strict_types=1);

use Asciisd\CashierPaytiko\Services\PaytikoSignatureService;
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    $this->signatureService = new PaytikoSignatureService('test-secret-key');
    $this->coreUrl = 'https://uat-core.paytiko.com'; // Using UAT for testing
    $this->merchantSecretKey = 'test-merchant-secret';
});

it('can resync webhooks for specific order IDs', function () {
    $mockHandler = new MockHandler([
        new Response(200, [], json_encode([
            'resyncedCount' => 2,
            'message' => 'Webhooks resynced successfully',
            'resyncedOrders' => ['order-123', 'order-456'],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $service = new PaytikoWebhookResyncService(
        $client,
        $this->signatureService,
        $this->coreUrl,
        $this->merchantSecretKey
    );

    $result = $service->resyncWebhooks(['order-123', 'order-456']);

    expect($result)->toMatchArray([
        'success' => true,
        'resynced_count' => 2,
        'message' => 'Webhooks resynced successfully',
        'resynced_orders' => ['order-123', 'order-456'],
    ]);
});

it('can resync webhooks by date range', function () {
    $mockHandler = new MockHandler([
        new Response(200, [], json_encode([
            'resyncedCount' => 5,
            'message' => 'Webhooks resynced successfully',
            'resyncedOrders' => ['order-1', 'order-2', 'order-3', 'order-4', 'order-5'],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $service = new PaytikoWebhookResyncService(
        $client,
        $this->signatureService,
        $this->coreUrl,
        $this->merchantSecretKey
    );

    $result = $service->resyncWebhooksByDateRange(
        '2024-01-01 00:00:00',
        '2024-01-31 23:59:59',
        ['SALE', 'REFUND']
    );

    expect($result)->toMatchArray([
        'success' => true,
        'resynced_count' => 5,
        'message' => 'Webhooks resynced successfully',
    ]);
});

it('can get resync status', function () {
    $mockHandler = new MockHandler([
        new Response(200, [], json_encode([
            'status' => 'completed',
            'progress' => 100,
            'totalWebhooks' => 10,
            'processedWebhooks' => 10,
            'failedWebhooks' => 0,
        ])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $service = new PaytikoWebhookResyncService(
        $client,
        $this->signatureService,
        $this->coreUrl,
        $this->merchantSecretKey
    );

    $result = $service->getResyncStatus('resync-123');

    expect($result)->toMatchArray([
        'success' => true,
        'status' => 'completed',
        'progress' => 100,
        'total_webhooks' => 10,
        'processed_webhooks' => 10,
        'failed_webhooks' => 0,
    ]);
});

it('handles API errors gracefully', function () {
    $mockHandler = new MockHandler([
        new RequestException(
            'Bad Request',
            new Request('POST', '/api/webhook/resync'),
            new Response(400, [], json_encode([
                'title' => 'Invalid order IDs',
                'errors' => ['order_ids' => ['Order IDs not found']],
            ]))
        ),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $service = new PaytikoWebhookResyncService(
        $client,
        $this->signatureService,
        $this->coreUrl,
        $this->merchantSecretKey
    );

    $result = $service->resyncWebhooks(['invalid-order']);

    expect($result)->toMatchArray([
        'success' => false,
        'error' => 'Invalid order IDs',
    ]);
});

it('can process resynced webhook data', function () {
    $client = new Client();
    
    $service = new PaytikoWebhookResyncService(
        $client,
        $this->signatureService,
        $this->coreUrl,
        $this->merchantSecretKey
    );

    $webhookPayload = [
        'OrderId' => 'test-order-123',
        'AccountId' => 'test-account-456',
        'AccountDetails' => [
            'MerchantId' => 12345,
            'CreatedDate' => '2024-01-01T10:00:00Z',
            'FirstName' => 'John',
            'LastName' => 'Doe',
            'Email' => 'john.doe@example.com',
            'Currency' => 'USD',
            'Country' => 'US',
            'Dob' => '1990-01-01',
            'City' => 'New York',
            'ZipCode' => '10001',
            'Region' => 'NY',
            'Street' => '123 Main St',
            'Phone' => '+1234567890',
        ],
        'TransactionType' => 'SALE',
        'TransactionStatus' => 'SUCCESS',
        'InitialAmount' => 100.00,
        'Currency' => 'USD',
        'TransactionId' => 789,
        'ExternalTransactionId' => 'ext-txn-101',
        'PaymentProcessor' => 'stripe',
        'IssueDate' => '2024-01-01T10:00:00Z',
        'InternalPspId' => 'psp-123',
        'Signature' => 'test-signature',
    ];

    $result = $service->processResyncedWebhook($webhookPayload);

    expect($result)->toBeTrue();
});
