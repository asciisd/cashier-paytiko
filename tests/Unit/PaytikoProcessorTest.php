<?php

declare(strict_types=1);

use Asciisd\CashierPaytiko\PaytikoProcessor;
use Asciisd\CashierCore\Exceptions\InvalidPaymentDataException;

it('validates payment data correctly', function () {
    $processor = new PaytikoProcessor([
        'merchant_secret_key' => 'test_secret',
        'core_url' => 'https://test.paytiko.com',
    ]);
    
    $validData = [
        'amount' => 2000,
        'currency' => 'USD',
        'order_id' => 'test_order_123',
        'billing_details' => [
            'first_name' => 'John',
            'email' => 'john@example.com',
            'country' => 'US',
            'phone' => '+1234567890',
        ],
    ];
    
    $validated = $processor->validatePaymentData($validData);
    
    expect($validated)->toBeArray()
        ->and($validated['amount'])->toBe(2000)
        ->and($validated['order_id'])->toBe('test_order_123');
});

it('throws exception for invalid payment data', function () {
    $processor = new PaytikoProcessor([
        'merchant_secret_key' => 'test_secret',
        'core_url' => 'https://test.paytiko.com',
    ]);
    
    $invalidData = [
        'amount' => -100, // Invalid amount
    ];
    
    expect(fn() => $processor->validatePaymentData($invalidData))
        ->toThrow(InvalidPaymentDataException::class);
});

it('returns correct processor name', function () {
    $processor = new PaytikoProcessor();
    
    expect($processor->getName())->toBe('paytiko');
});

it('supports correct features', function () {
    $processor = new PaytikoProcessor();
    
    expect($processor->supports('charge'))->toBeTrue()
        ->and($processor->supports('hosted_page'))->toBeTrue()
        ->and($processor->supports('refund'))->toBeFalse();
});
