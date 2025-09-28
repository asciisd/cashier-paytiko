<?php

declare(strict_types=1);

use Asciisd\CashierPaytiko\Events\PaytikoPaymentSuccessful;
use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;
use Illuminate\Support\Facades\Event;

it('handles valid webhook successfully', function () {
    Event::fake();
    
    $payload = [
        'OrderId' => '3aa1e912-6ff3-4925-82dc-66bd7927676c',
        'AccountId' => 'c6aad9e8-4fe6-4895b82-cc1ea66b4aad',
        'AccountDetails' => [
            'MerchantId' => 20115,
            'CreatedDate' => '2022-07-18T10:18:31.67087+00:00',
            'FirstName' => 'John',
            'LastName' => 'Doe',
            'Email' => 'john@doe.com',
            'Currency' => 'GBP',
            'Country' => 'GB',
            'Dob' => '12/16/1990',
            'City' => 'London',
            'ZipCode' => '5123',
            'Region' => 'London',
            'Street' => 'Baker Str. 12',
            'Phone' => '+44839958434',
        ],
        'TransactionType' => 'PayIn',
        'TransactionStatus' => 'Success',
        'InitialAmount' => 12.58,
        'Currency' => 'EUR',
        'TransactionId' => 179411,
        'ExternalTransactionId' => '63793736354518895200',
        'PaymentProcessor' => 'World Pay',
        'DeclineReasonText' => null,
        'CardType' => 'Visa',
        'LastCcDigits' => '5432',
        'IssueDate' => '2023-03-13T14:12:33.2372322+00:00',
        'InternalPspId' => '5456650000021055202',
        'MaskedPan' => '455636******5432',
        'Signature' => hash('sha256', 'test_secret_key:3aa1e912-6ff3-4925-82dc-66bd7927676c'),
    ];
    
    $response = $this->postJson('/api/webhooks/paytiko', $payload);
    
    $response->assertOk()
        ->assertJson(['status' => 'success']);
    
    Event::assertDispatched(PaytikoWebhookReceived::class);
    Event::assertDispatched(PaytikoPaymentSuccessful::class);
});

it('rejects webhook with invalid signature', function () {
    config(['cashier-paytiko.webhook.verify_signature' => true]);
    
    $payload = [
        'OrderId' => '3aa1e912-6ff3-4925-82dc-66bd7927676c',
        'AccountDetails' => [
            'MerchantId' => 20115,
            'CreatedDate' => '2022-07-18T10:18:31.67087+00:00',
            'FirstName' => 'John',
            'LastName' => 'Doe',
            'Email' => 'john@doe.com',
            'Currency' => 'GBP',
            'Country' => 'GB',
            'Dob' => '12/16/1990',
            'City' => 'London',
            'ZipCode' => '5123',
            'Region' => 'London',
            'Street' => 'Baker Str. 12',
            'Phone' => '+44839958434',
        ],
        'TransactionType' => 'PayIn',
        'TransactionStatus' => 'Success',
        'Signature' => 'invalid_signature',
    ];
    
    $response = $this->postJson('/api/webhooks/paytiko', $payload);
    
    $response->assertStatus(400)
        ->assertJson(['error' => 'Invalid signature']);
});
