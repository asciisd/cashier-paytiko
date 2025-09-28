<?php

declare(strict_types=1);

use Asciisd\CashierPaytiko\Services\PaytikoSignatureService;

it('generates correct hosted page signature', function () {
    $service = new PaytikoSignatureService('test_secret_key');
    
    $email = 'test@example.com';
    $timestamp = 1669923838;
    
    $signature = $service->generateHostedPageSignature($email, $timestamp);
    
    $expectedRaw = "{$email};{$timestamp};test_secret_key";
    $expected = hash('sha256', $expectedRaw);
    
    expect($signature)->toBe($expected);
});

it('generates correct webhook signature', function () {
    $service = new PaytikoSignatureService('test_secret_key');
    
    $orderId = '3aa1e912-6ff3-4925-82dc-66bd7927676c';
    
    $signature = $service->generateWebhookSignature($orderId);
    
    $expectedRaw = "test_secret_key:{$orderId}";
    $expected = hash('sha256', $expectedRaw);
    
    expect($signature)->toBe($expected);
});
