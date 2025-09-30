<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Asciisd\CashierPaytiko\PaytikoProcessor;

/**
 * Example demonstrating the simplified charge method
 * 
 * This shows how to use the new simpleCharge() method which handles
 * all the configuration merging internally.
 */

// Initialize the processor with config
$processor = new PaytikoProcessor([
    'merchant_secret_key' => 'your_secret_key',
    'core_url' => 'https://api.paytiko.com',
    'default_currency' => 'USD',
    'webhook_url' => 'https://yourdomain.com/webhook',
    'success_redirect_url' => 'https://yourdomain.com/success',
    'failed_redirect_url' => 'https://yourdomain.com/failed',
]);

// Example 1: Simple charge with just amount
echo "=== Example 1: Simple Charge ===\n";
try {
    $result = $processor->simpleCharge(100.00, [
        'billing_details' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'country' => 'US',
            'phone' => '+1234567890',
        ]
    ]);
    
    echo "Success: " . ($result->isSuccessful() ? 'Yes' : 'No') . "\n";
    echo "Transaction ID: " . $result->transactionId . "\n";
    echo "Amount: " . $result->amount . "\n";
    echo "Currency: " . $result->currency . "\n";
    echo "Redirect URL: " . $result->metadata['redirect_url'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Example 2: Custom Parameters ===\n";
try {
    $result = $processor->simpleCharge(250.50, [
        'currency' => 'EUR',
        'description' => 'Custom payment for premium features',
        'order_id' => 'custom_order_123',
        'billing_details' => [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'country' => 'CA',
            'phone' => '+1555123456',
        ],
        'metadata' => [
            'user_id' => 123,
            'subscription_type' => 'premium'
        ]
    ]);
    
    echo "Success: " . ($result->isSuccessful() ? 'Yes' : 'No') . "\n";
    echo "Transaction ID: " . $result->transactionId . "\n";
    echo "Amount: " . $result->amount . "\n";
    echo "Currency: " . $result->currency . "\n";
    echo "Redirect URL: " . $result->metadata['redirect_url'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Example 3: Override URLs ===\n";
try {
    $result = $processor->simpleCharge(75.00, [
        'billing_details' => [
            'first_name' => 'Bob',
            'last_name' => 'Johnson',
            'email' => 'bob@example.com',
            'country' => 'GB',
            'phone' => '+44123456789',
        ],
        // Override default URLs for this specific payment
        'webhook_url' => 'https://customdomain.com/special-webhook',
        'success_redirect_url' => 'https://customdomain.com/special-success',
        'failed_redirect_url' => 'https://customdomain.com/special-failed',
    ]);
    
    echo "Success: " . ($result->isSuccessful() ? 'Yes' : 'No') . "\n";
    echo "Transaction ID: " . $result->transactionId . "\n";
    echo "Amount: " . $result->amount . "\n";
    echo "Currency: " . $result->currency . "\n";
    echo "Redirect URL: " . $result->metadata['redirect_url'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Benefits of simplified charge method ===\n";
echo "✅ No need to manually set URLs - they come from config\n";
echo "✅ Automatic order ID generation if not provided\n";
echo "✅ Automatic description generation if not provided\n";
echo "✅ Fallback to Laravel routes if config URLs not set\n";
echo "✅ Clean, simple interface: charge(\$amount, \$params)\n";
echo "✅ Easy to extend with additional parameters\n";
echo "✅ Backward compatible with existing charge() method\n";
