---
name: cashier-paytiko-development
description: "Build and integrate Paytiko payment flows with the asciisd/cashier-paytiko package. Activates when working with PaytikoProcessor, PaytikoAdapter, hosted page payments, Paytiko webhook handling, PaytikoWebhookData, PaytikoSignatureService, PaytikoWebhookResyncService, PaytikoHostedPageService, Paytiko billing details, or configuring cashier-paytiko.php."
---

# Cashier Paytiko Development

## Package Overview

`asciisd/cashier-paytiko` is a Paytiko payment processor implementation for `asciisd/cashier-core`. It provides hosted page payment flows, webhook handling with signature verification, and webhook resync capabilities via the Paytiko gateway.

**Namespace:** `Asciisd\CashierPaytiko`
**Requires:** `asciisd/cashier-core ^1.0`

## Architecture

```
src/
├── PaytikoProcessor.php              — Payment processor (extends AbstractPaymentProcessor)
├── CashierPaytikoServiceProvider.php  — Service provider (config, bindings, routes)
├── Adapters/
│   └── PaytikoAdapter.php            — Maps Paytiko responses → cashier-core DTOs
├── DataObjects/
│   ├── PaytikoHostedPageRequest.php  — Hosted page API request
│   ├── PaytikoHostedPageResponse.php — Hosted page API response
│   ├── PaytikoBillingDetails.php     — Billing details for hosted page
│   ├── PaytikoAccountDetails.php     — Merchant account info from webhook
│   └── PaytikoWebhookData.php        — Parsed webhook payload
├── Events/
│   ├── PaytikoPaymentSuccessful.php  — Successful payment event
│   ├── PaytikoPaymentFailed.php      — Failed payment event
│   ├── PaytikoRefundProcessed.php    — Refund event
│   └── PaytikoWebhookReceived.php    — General webhook event (raw + parsed)
├── Http/Controllers/
│   └── PaytikoWebhookController.php  — Webhook + resync endpoints
└── Services/
    ├── PaytikoHostedPageService.php   — Creates hosted pages via Paytiko API
    ├── PaytikoSignatureService.php    — SHA256 signature generation
    └── PaytikoWebhookResyncService.php — Resync missed webhooks
```

## When to Use This Skill

Use this skill when:
- Creating or modifying Paytiko payment integrations
- Working with Paytiko hosted page flows
- Handling Paytiko webhook events (`PaytikoPaymentSuccessful`, `PaytikoPaymentFailed`, etc.)
- Implementing webhook listeners for Paytiko events
- Configuring `cashier-paytiko.php`
- Working with Paytiko webhook resync functionality
- Debugging Paytiko payment or webhook issues
- Writing tests for Paytiko payment flows

## Payment Flow

Paytiko uses a **hosted page redirect** model:

1. Call `charge()` or `simpleCharge()` → creates a hosted page via Paytiko API
2. Returns `PaymentResult` with `Pending` status and `redirect_url` in metadata
3. Redirect user to the hosted page URL
4. User completes payment on Paytiko's hosted page
5. Paytiko sends webhook to `api/webhooks/paytiko` with result
6. `PaytikoWebhookController` verifies signature, parses data, dispatches events
7. Application listeners handle `PaytikoPaymentSuccessful` / `PaytikoPaymentFailed`

## Charging Payments

### Simple Charge (Recommended)

`simpleCharge()` accepts amount and optional parameters, using config fallbacks for URLs and currency:

```php
use Asciisd\CashierCore\Facades\PaymentFactory;

$processor = PaymentFactory::create('paytiko');
$result = $processor->simpleCharge(100, [
    'billing_details' => [
        'first_name' => $user->first_name,
        'email' => $user->email,
        'country' => $user->country,
        'phone' => $user->phone,
    ],
]);

if ($result->isSuccessful()) {
    $redirectUrl = $result->metadata['redirect_url'];
    return redirect($redirectUrl);
}
```

### Full Charge

`charge()` requires complete validated data:

```php
$result = $processor->charge([
    'amount' => 100,
    'currency' => 'USD',
    'order_id' => 'deposit-' . time(),
    'description' => 'Account deposit',
    'billing_details' => [
        'first_name' => 'John',
        'email' => 'john@example.com',
        'country' => 'US',
        'phone' => '+1234567890',
        'currency' => 'USD',
        'locked_amount' => 100,
        'last_name' => 'Doe',          // optional
        'street' => '123 Main St',     // optional
        'city' => 'New York',          // optional
        'region' => 'NY',              // optional
        'zip_code' => '10001',         // optional
        'date_of_birth' => '1990-01-15', // optional, Y-m-d
        'gender' => 'Male',            // optional, Male|Female
    ],
    'webhook_url' => 'https://example.com/api/webhooks/paytiko',
    'success_redirect_url' => 'https://example.com/payment/success',
    'failed_redirect_url' => 'https://example.com/payment/failed',
    'credit_card_only' => true,     // optional
    'is_pay_out' => false,          // optional
    'fixed_pp_id' => 42,           // optional, locks to specific PSP
    'disabled_psp_ids' => [1, 5],  // optional, exclude PSPs
]);
```

### Required Validation Rules

| Field | Rule |
|-------|------|
| `amount` | `required\|numeric\|min:0.01` |
| `order_id` | `required\|string` |
| `billing_details` | `required\|array` |
| `billing_details.first_name` | `required\|string\|max:255` |
| `billing_details.email` | `required\|email\|max:255` |
| `billing_details.country` | `required\|string\|size:2` |
| `billing_details.phone` | `required\|string\|max:20` |

## Handling Webhooks

### Event Listeners

Listen for Paytiko events in your application's `EventServiceProvider` or using listener classes:

```php
use Asciisd\CashierPaytiko\Events\PaytikoPaymentSuccessful;
use Asciisd\CashierPaytiko\Events\PaytikoPaymentFailed;
use Asciisd\CashierPaytiko\Events\PaytikoRefundProcessed;
use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;

// In a listener class:
class HandlePaytikoPaymentSuccessful
{
    public function handle(PaytikoPaymentSuccessful $event): void
    {
        $webhookData = $event->webhookData;

        // Access webhook data properties
        $orderId = $webhookData->orderId;
        $amount = $webhookData->initialAmount;
        $currency = $webhookData->currency;
        $transactionId = $webhookData->transactionId;
        $cardType = $webhookData->cardType;
        $lastCcDigits = $webhookData->lastCcDigits;

        // Check transaction type
        if ($webhookData->isPayIn()) {
            // Handle deposit
        }
    }
}
```

### PaytikoWebhookData Properties

The `PaytikoWebhookData` DTO contains all parsed webhook fields:

| Property | Type | Description |
|----------|------|-------------|
| `orderId` | `string` | Your order ID |
| `accountId` | `string` | Paytiko account ID |
| `accountDetails` | `PaytikoAccountDetails` | Customer details from Paytiko |
| `transactionType` | `string` | `PayIn`, `PayOut`, `Refund` |
| `transactionStatus` | `string` | `Success`, `Rejected`, `Failed`, etc. |
| `initialAmount` | `float` | Requested amount |
| `currency` | `string` | Currency code |
| `transactionId` | `int` | Paytiko transaction ID |
| `externalTransactionId` | `string` | External transaction ID |
| `paymentProcessor` | `string` | PSP name |
| `issueDate` | `string` | Transaction date |
| `declineReasonText` | `?string` | Decline reason if failed |
| `cardType` | `?string` | Card brand (Visa, Mastercard, etc.) |
| `lastCcDigits` | `?string` | Last 4 digits of card |
| `maskedPan` | `?string` | Masked card number |

Helper methods: `isSuccessful()`, `isRejected()`, `isFailed()`, `isPayIn()`, `isPayOut()`, `isRefund()`.

## PaytikoAdapter Status Mapping

| Paytiko Status | PaymentStatus Enum |
|----------------|-------------------|
| `success`, `approved`, `completed` | `Succeeded` |
| `failed`, `error`, `rejected`, `declined` | `Failed` |
| `pending`, `created` | `Pending` |
| `processing`, `in_progress` | `Processing` |
| `canceled`, `cancelled` | `Canceled` |

## Webhook Resync

Use `PaytikoWebhookResyncService` to recover missed webhooks:

```php
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;

$resyncService = app(PaytikoWebhookResyncService::class);

// Resync single webhook
$result = $resyncService->resyncSingleWebhook('order-123');

// Resync multiple webhooks
$result = $resyncService->resyncWebhooks(['order-123', 'order-456']);

// Resync by date range
$result = $resyncService->resyncWebhooksByDateRange(
    '2025-01-01 00:00:00',
    '2025-01-31 23:59:59',
    ['SALE', 'REFUND'] // optional transaction type filter
);

// Extract webhook payload for a specific order
$result = $resyncService->extractWebhookPayload('order-123');
if ($result['success']) {
    $payload = $result['payload'];
}
```

### Resync REST Endpoints (auto-registered)

| Route | Method | Name | Purpose |
|-------|--------|------|---------|
| `api/webhooks/paytiko` | POST | `paytiko.webhook` | Main webhook handler |
| `api/webhooks/paytiko/resync` | POST | `paytiko.webhook.resync` | Resync by order IDs |
| `api/webhooks/paytiko/resync-by-date` | POST | `paytiko.webhook.resync-by-date` | Resync by date range |
| `api/webhooks/paytiko/resync-status/{resyncId}` | GET | `paytiko.webhook.resync-status` | Check resync job status |
| `api/webhooks/paytiko/process-resynced` | POST | `paytiko.webhook.process-resynced` | Process a resynced webhook |

## Signature Verification

Signatures use SHA256 hashing via `PaytikoSignatureService`:

- **Hosted page signature:** `SHA256("{email};{timestamp};{merchant_secret_key}")`
- **Webhook signature:** `SHA256("{merchant_secret_key}:{orderId}")`

Webhook signature verification is enabled by default (`cashier-paytiko.webhook.verify_signature`). The controller checks the `Signature` field in webhook payloads against the calculated signature.

## Configuration

Config file: `config/cashier-paytiko.php`

| Key | Env Variable | Default | Description |
|-----|-------------|---------|-------------|
| `merchant_secret_key` | `PAYTIKO_MERCHANT_SECRET_KEY` | — | Merchant secret key |
| `core_url` | `PAYTIKO_CORE_URL` | `https://core.paytiko.com` | API base URL (UAT: `https://uat-core.paytiko.com`) |
| `webhook_url` | `PAYTIKO_WEBHOOK_URL` | — | Webhook callback URL |
| `success_redirect_url` | `PAYTIKO_SUCCESS_REDIRECT_URL` | — | Success redirect URL |
| `failed_redirect_url` | `PAYTIKO_FAILED_REDIRECT_URL` | — | Failed redirect URL |
| `default_currency` | `PAYTIKO_DEFAULT_CURRENCY` | `USD` | Default currency |
| `webhook.verify_signature` | `PAYTIKO_VERIFY_WEBHOOK_SIGNATURE` | `true` | Enable signature verification |
| `webhook.tolerance` | `PAYTIKO_WEBHOOK_TOLERANCE` | `300` | Tolerance in seconds (5 min) |
| `http.timeout` | `PAYTIKO_HTTP_TIMEOUT` | `30` | HTTP timeout |
| `http.connect_timeout` | `PAYTIKO_HTTP_CONNECT_TIMEOUT` | `10` | Connection timeout |
| `http.verify` | `PAYTIKO_HTTP_VERIFY_SSL` | `true` | SSL verification |
| `logging.enabled` | `PAYTIKO_LOGGING_ENABLED` | `true` | Enable logging |
| `logging.channel` | `PAYTIKO_LOG_CHANNEL` | `default` | Log channel |

Publish config: `php artisan vendor:publish --tag=cashier-paytiko-config`

## Processor Registration

Register in `config/cashier-core.php`:

```php
'processors' => [
    'paytiko' => [
        'class' => \Asciisd\CashierPaytiko\PaytikoProcessor::class,
        'config' => [
            'merchant_secret_key' => config('cashier-paytiko.merchant_secret_key'),
            'core_url' => config('cashier-paytiko.core_url'),
            'webhook_url' => config('cashier-paytiko.webhook_url'),
            'success_redirect_url' => config('cashier-paytiko.success_redirect_url'),
            'failed_redirect_url' => config('cashier-paytiko.failed_redirect_url'),
            'default_currency' => config('cashier-paytiko.default_currency'),
        ],
    ],
],
```

## Limitations

- `refund()` throws `BadMethodCallException` — refunds are processed through Paytiko admin panel only.
- `getPaymentStatus()` throws `BadMethodCallException` — status updates are received exclusively via webhooks.
- `retrieve()` uses `PaytikoWebhookResyncService::extractWebhookPayload()` to fetch transaction data from the Paytiko API.
- Direct API status queries are not supported; rely on webhook events for real-time updates.

## BillingTransformerInterface

When using the cashier-core `BillingTransformerInterface` with Paytiko, ensure the transformer populates the required billing fields:

```php
use Asciisd\CashierCore\Contracts\BillingTransformerInterface;
use Illuminate\Contracts\Auth\Authenticatable;

class PaytikoBillingTransformer implements BillingTransformerInterface
{
    public function transform(Authenticatable $user, array $paymentData): array
    {
        return array_merge($paymentData, [
            'billing_details' => [
                'first_name' => $user->first_name,
                'email' => $user->email,
                'country' => $user->country,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function canHandle(string $processor): bool
    {
        return $processor === 'paytiko';
    }

    public function getProcessorName(): string
    {
        return 'paytiko';
    }
}
```
