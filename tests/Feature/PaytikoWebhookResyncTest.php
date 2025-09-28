<?php

declare(strict_types=1);

use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
    
    // Mock the resync service
    $mockHandler = new MockHandler([
        new Response(200, [], json_encode([
            'resyncedCount' => 2,
            'message' => 'Webhooks resynced successfully',
            'resyncedOrders' => ['order-123', 'order-456'],
        ])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);

    $this->app->instance(Client::class, $client);
});

it('can resync webhooks via API endpoint', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync', [
        'order_ids' => ['order-123', 'order-456'],
        'start_date' => '2024-01-01 00:00:00',
        'end_date' => '2024-01-31 23:59:59',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Webhooks resynced successfully',
            'resynced_count' => 2,
            'resynced_orders' => ['order-123', 'order-456'],
        ]);
});

it('validates required fields for webhook resync', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_ids']);
});

it('validates order_ids array format', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync', [
        'order_ids' => 'invalid-format',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['order_ids']);
});

it('validates date format for resync', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync', [
        'order_ids' => ['order-123'],
        'start_date' => 'invalid-date',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['start_date']);
});

it('can resync webhooks by date range via API endpoint', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync-by-date', [
        'start_date' => '2024-01-01 00:00:00',
        'end_date' => '2024-01-31 23:59:59',
        'transaction_types' => ['SALE', 'REFUND'],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Webhooks resynced successfully',
            'resynced_count' => 2,
        ]);
});

it('validates date range for resync by date', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync-by-date', [
        'start_date' => '2024-01-31 23:59:59',
        'end_date' => '2024-01-01 00:00:00', // End date before start date
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);
});

it('validates transaction types for resync by date', function () {
    $response = $this->postJson('/api/webhooks/paytiko/resync-by-date', [
        'start_date' => '2024-01-01 00:00:00',
        'end_date' => '2024-01-31 23:59:59',
        'transaction_types' => ['INVALID_TYPE'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['transaction_types.0']);
});

it('can get resync status via API endpoint', function () {
    // Mock successful status response
    $mockHandler = new MockHandler([
        new Response(200, [], json_encode([
            'status' => 'in_progress',
            'progress' => 50,
            'totalWebhooks' => 10,
            'processedWebhooks' => 5,
            'failedWebhooks' => 0,
        ])),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);
    $this->app->instance(Client::class, $client);

    $response = $this->getJson('/api/webhooks/paytiko/resync-status/resync-123');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'status' => 'in_progress',
            'progress' => 50,
            'total_webhooks' => 10,
            'processed_webhooks' => 5,
            'failed_webhooks' => 0,
        ]);
});

it('can process resynced webhook via API endpoint', function () {
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

    // Mock signature verification to pass
    config(['cashier-paytiko.webhook.verify_signature' => false]);

    $response = $this->postJson('/api/webhooks/paytiko/process-resynced', $webhookPayload);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'message' => 'Resynced webhook processed successfully',
        ]);

    // Verify that the webhook received event was dispatched
    Event::assertDispatched(PaytikoWebhookReceived::class);
});

it('handles API errors gracefully in resync endpoint', function () {
    // Mock API error response
    $mockHandler = new MockHandler([
        new \GuzzleHttp\Exception\RequestException(
            'Bad Request',
            new \GuzzleHttp\Psr7\Request('POST', '/api/webhook/resync'),
            new Response(400, [], json_encode([
                'title' => 'Invalid order IDs',
                'errors' => ['order_ids' => ['Order IDs not found']],
            ]))
        ),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);
    $this->app->instance(Client::class, $client);

    $response = $this->postJson('/api/webhooks/paytiko/resync', [
        'order_ids' => ['invalid-order'],
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Invalid order IDs',
        ]);
});

it('handles network errors gracefully', function () {
    // Mock network error
    $mockHandler = new MockHandler([
        new \GuzzleHttp\Exception\ConnectException(
            'Connection timeout',
            new \GuzzleHttp\Psr7\Request('POST', '/api/webhook/resync')
        ),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new Client(['handler' => $handlerStack]);
    $this->app->instance(Client::class, $client);

    $response = $this->postJson('/api/webhooks/paytiko/resync', [
        'order_ids' => ['order-123'],
    ]);

    $response->assertStatus(400)
        ->assertJson([
            'error' => 'Connection timeout',
        ]);
});
