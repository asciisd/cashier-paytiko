# Cashier Paytiko

[![Latest Version on Packagist](https://img.shields.io/packagist/v/asciisd/cashier-paytiko.svg?style=flat-square)](https://packagist.org/packages/asciisd/cashier-paytiko)
[![Total Downloads](https://img.shields.io/packagist/dt/asciisd/cashier-paytiko.svg?style=flat-square)](https://packagist.org/packages/asciisd/cashier-paytiko)
[![License](https://img.shields.io/packagist/l/asciisd/cashier-paytiko.svg?style=flat-square)](https://packagist.org/packages/asciisd/cashier-paytiko)

A Laravel package that integrates Paytiko payment processor with [Cashier Core](https://github.com/asciisd/cashier-core). This package provides a clean, scalable implementation of Paytiko's Hosted Page solution with comprehensive webhook handling.

## Features

- **Hosted Page Integration**: Seamless integration with Paytiko's hosted payment pages
- **Webhook Handling**: Automatic webhook processing with signature verification
- **Event System**: Laravel events for payment status updates
- **DTO Architecture**: Clean data transfer objects for type safety
- **Comprehensive Testing**: Full test suite with Pest
- **Scalable Design**: Easy to extend for additional Paytiko features
- **Laravel Integration**: Native Laravel package with service provider
- **Signature Security**: Automatic signature generation and verification

## Installation

Install the package via Composer:

```bash
composer require asciisd/cashier-paytiko
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Asciisd\CashierPaytiko\CashierPaytikoServiceProvider" --tag="cashier-paytiko-config"
```

## Configuration

Add the following environment variables to your `.env` file:

```env
# Paytiko Configuration
PAYTIKO_MERCHANT_SECRET_KEY=your_merchant_secret_key
PAYTIKO_CORE_URL=https://your-paytiko-core-url.com
PAYTIKO_WEBHOOK_URL=https://yoursite.com/api/webhooks/paytiko
PAYTIKO_SUCCESS_REDIRECT_URL=https://yoursite.com/payment/success
PAYTIKO_FAILED_REDIRECT_URL=https://yoursite.com/payment/failed
PAYTIKO_DEFAULT_CURRENCY=USD

# Optional Settings
PAYTIKO_VERIFY_WEBHOOK_SIGNATURE=true
PAYTIKO_WEBHOOK_TOLERANCE=300
PAYTIKO_HTTP_TIMEOUT=30
PAYTIKO_LOGGING_ENABLED=true
```

## Usage

### Basic Payment Processing

```php
use Asciisd\CashierCore\Facades\PaymentFactory;

// Create Paytiko processor
$processor = PaymentFactory::create('paytiko', [
    'merchant_secret_key' => config('cashier-paytiko.merchant_secret_key'),
    'core_url' => config('cashier-paytiko.core_url'),
]);

// Process payment (creates hosted page)
$result = $processor->charge([
    'amount' => 2000, // $20.00 in cents
    'currency' => 'USD',
    'order_id' => 'order_12345',
    'description' => 'Product purchase',
    'billing_details' => [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'country' => 'US',
        'phone' => '+1234567890',
        'street' => '123 Main St',
        'city' => 'New York',
        'region' => 'NY',
        'zip_code' => '10001',
        'date_of_birth' => '1990-01-01',
        'gender' => 'Male',
        'currency' => 'USD',
    ],
    'webhook_url' => 'https://yoursite.com/api/webhooks/paytiko',
    'success_redirect_url' => 'https://yoursite.com/success',
    'failed_redirect_url' => 'https://yoursite.com/failed',
]);

if ($result->isSuccessful()) {
    $redirectUrl = $result->metadata['redirect_url'];
    // Redirect user to Paytiko hosted page
    return redirect($redirectUrl);
} else {
    // Handle error
    echo "Payment failed: {$result->message}";
}
```

### Registering with Cashier Core

Add Paytiko to your `config/cashier-core.php`:

```php
'processors' => [
    'paytiko' => [
        'class' => \Asciisd\CashierPaytiko\PaytikoProcessor::class,
        'config' => [
            'merchant_secret_key' => config('cashier-paytiko.merchant_secret_key'),
            'core_url' => config('cashier-paytiko.core_url'),
        ],
    ],
],
```

### Webhook Handling

The package automatically registers a webhook endpoint at `/api/webhooks/paytiko`. Configure this URL in your Paytiko dashboard.

#### Listening to Events

```php
use Asciisd\CashierPaytiko\Events\PaytikoPaymentSuccessful;
use Asciisd\CashierPaytiko\Events\PaytikoPaymentFailed;
use Asciisd\CashierPaytiko\Events\PaytikoRefundProcessed;
use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;

// Listen to successful payments
Event::listen(PaytikoPaymentSuccessful::class, function ($event) {
    $webhookData = $event->webhookData;
    
    // Update your order status
    $order = Order::where('id', $webhookData->orderId)->first();
    $order->update(['status' => 'paid']);
    
    // Send confirmation email, etc.
});

// Listen to failed payments
Event::listen(PaytikoPaymentFailed::class, function ($event) {
    $webhookData = $event->webhookData;
    
    // Handle failed payment
    $order = Order::where('id', $webhookData->orderId)->first();
    $order->update(['status' => 'failed']);
});

// Listen to all webhook events
Event::listen(PaytikoWebhookReceived::class, function ($event) {
    $webhookData = $event->webhookData;
    $rawPayload = $event->rawPayload;
    
    // Log webhook for debugging
    Log::info('Paytiko webhook received', [
        'order_id' => $webhookData->orderId,
        'status' => $webhookData->transactionStatus,
    ]);
});
```

### Advanced Configuration

#### Custom Webhook URL per Payment

```php
$result = $processor->charge([
    // ... other data
    'webhook_url' => 'https://yoursite.com/custom/webhook/endpoint',
]);
```

#### Credit Card Only Mode

```php
$result = $processor->charge([
    // ... other data
    'credit_card_only' => true,
]);
```

#### Disable Specific Payment Processors

```php
$result = $processor->charge([
    // ... other data
    'disabled_psp_ids' => [12321, 54455, 34212],
]);
```

#### Payout Mode

```php
$result = $processor->charge([
    // ... other data
    'is_pay_out' => true,
]);
```

## Data Transfer Objects

The package uses DTOs for type safety and clean data handling:

### PaytikoHostedPageRequest

```php
use Asciisd\CashierPaytiko\DataObjects\PaytikoHostedPageRequest;
use Asciisd\CashierPaytiko\DataObjects\PaytikoBillingDetails;

$billingDetails = new PaytikoBillingDetails(
    firstName: 'John',
    email: 'john@example.com',
    country: 'US',
    phone: '+1234567890',
    currency: 'USD'
);

$request = new PaytikoHostedPageRequest(
    timestamp: (string) time(),
    orderId: 'order_123',
    signature: $signature,
    billingDetails: $billingDetails
);
```

### PaytikoWebhookData

```php
use Asciisd\CashierPaytiko\DataObjects\PaytikoWebhookData;

// Webhook data is automatically parsed from incoming webhooks
$webhookData = $event->webhookData;

// Check payment status
if ($webhookData->isSuccessful()) {
    // Payment succeeded
}

if ($webhookData->isPayIn()) {
    // This is a payment
}

if ($webhookData->isRefund()) {
    // This is a refund
}
```

## Testing

Run the test suite:

```bash
composer test
```

Run tests with coverage:

```bash
composer test-coverage
```

### Example Test

```php
use Asciisd\CashierPaytiko\PaytikoProcessor;

it('processes payment successfully', function () {
    $processor = new PaytikoProcessor([
        'merchant_secret_key' => 'test_secret',
        'core_url' => 'https://test.paytiko.com',
    ]);
    
    $paymentData = [
        'amount' => 2000,
        'order_id' => 'test_order',
        'billing_details' => [
            'first_name' => 'John',
            'email' => 'john@example.com',
            'country' => 'US',
            'phone' => '+1234567890',
        ],
    ];
    
    $result = $processor->charge($paymentData);
    
    expect($result->isSuccessful())->toBeTrue();
});
```

## Security

- **Signature Verification**: All webhooks are verified using SHA256 signatures
- **HTTPS Required**: All API communications use HTTPS
- **Input Validation**: Comprehensive validation of all input data
- **Error Handling**: Secure error handling without exposing sensitive data

## Extending the Package

The package is designed to be easily extensible. You can add new Paytiko features by:

1. Creating new DTOs for request/response data
2. Adding new methods to the `PaytikoProcessor`
3. Creating new events for additional webhook types
4. Extending the service classes for new API endpoints

### Example: Adding Direct Payment API

```php
// Add to PaytikoProcessor
public function directPayment(array $data): PaymentResult
{
    // Implement direct payment API integration
}

// Create new DTO
class PaytikoDirectPaymentRequest
{
    // Define direct payment request structure
}
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

For support, please open an issue on GitHub or contact us at <info@asciisd.com>.

## Changelog

### v1.0.0

- Initial release
- Hosted Page integration
- Webhook handling with events
- Comprehensive test suite
- Full documentation
