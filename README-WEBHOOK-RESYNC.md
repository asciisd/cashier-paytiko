# Paytiko Webhook Resync API

This document describes the webhook resync functionality implemented in the Cashier Paytiko package.

## Overview

The webhook resync API allows you to request Paytiko to resend webhook notifications for specific transactions or date ranges. This is useful for:

- Recovering missed webhook notifications due to downtime
- Reprocessing webhooks after system maintenance
- Debugging webhook-related issues
- Ensuring data consistency between your system and Paytiko

## Paytiko API Endpoints

The webhook resync functionality makes calls to the following Paytiko API endpoints:

- `POST {core_url}/api/webhook/resync` - Resync specific order IDs
- `POST {core_url}/api/webhook/resync-by-date` - Resync by date range
- `GET {core_url}/api/webhook/resync-status/{resyncId}` - Get resync status

Where `{core_url}` is:

- **UAT**: `https://uat-core.paytiko.com` (for testing)
- **PROD**: `https://core.paytiko.com` (for production)

## Available Endpoints

### 1. Resync Webhooks by Order IDs

**Endpoint:** `POST /api/webhooks/paytiko/resync`

**Request Body:**

```json
{
    "order_ids": ["order-123", "order-456"],
    "start_date": "2024-01-01 00:00:00",  // Optional
    "end_date": "2024-01-31 23:59:59"     // Optional
}
```

**Response:**

```json
{
    "success": true,
    "message": "Webhooks resynced successfully",
    "resynced_count": 2,
    "resynced_orders": ["order-123", "order-456"]
}
```

### 2. Resync Webhooks by Date Range

**Endpoint:** `POST /api/webhooks/paytiko/resync-by-date`

**Request Body:**

```json
{
    "start_date": "2024-01-01 00:00:00",
    "end_date": "2024-01-31 23:59:59",
    "transaction_types": ["SALE", "REFUND"]  // Optional
}
```

**Response:**

```json
{
    "success": true,
    "message": "Webhooks resynced successfully",
    "resynced_count": 15,
    "resynced_orders": ["order-1", "order-2", "..."]
}
```

### 3. Get Resync Status

**Endpoint:** `GET /api/webhooks/paytiko/resync-status/{resyncId}`

**Response:**

```json
{
    "success": true,
    "status": "in_progress",
    "progress": 75,
    "total_webhooks": 100,
    "processed_webhooks": 75,
    "failed_webhooks": 2
}
```

### 4. Process Resynced Webhook

**Endpoint:** `POST /api/webhooks/paytiko/process-resynced`

This endpoint accepts the same payload format as the regular webhook endpoint and processes it through the same event system.

## Configuration

The webhook resync functionality uses the same configuration as the main Paytiko integration:

```php
// config/cashier-paytiko.php
return [
    'merchant_secret_key' => env('PAYTIKO_MERCHANT_SECRET_KEY'),
    
    // Paytiko API Base URLs
    // UAT: https://uat-core.paytiko.com for testing
    // PROD: https://core.paytiko.com for production
    'core_url' => env('PAYTIKO_CORE_URL', 'https://core.paytiko.com'),
    
    'webhook' => [
        'verify_signature' => env('PAYTIKO_VERIFY_WEBHOOK_SIGNATURE', true),
    ],
    'http' => [
        'timeout' => env('PAYTIKO_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('PAYTIKO_HTTP_CONNECT_TIMEOUT', 10),
        'verify' => env('PAYTIKO_HTTP_VERIFY_SSL', true),
    ],
    'logging' => [
        'enabled' => env('PAYTIKO_LOGGING_ENABLED', true),
    ],
];
```

### Environment Variables

Add these to your `.env` file:

```env
# Paytiko Configuration
PAYTIKO_MERCHANT_SECRET_KEY=your-merchant-secret-key-here

# Paytiko API Base URL
# For UAT/Testing Environment:
PAYTIKO_CORE_URL=https://uat-core.paytiko.com
# For Production Environment:
# PAYTIKO_CORE_URL=https://core.paytiko.com

# Webhook URLs (update with your actual domain)
PAYTIKO_WEBHOOK_URL=https://yourdomain.com/api/webhooks/paytiko
PAYTIKO_SUCCESS_REDIRECT_URL=https://yourdomain.com/payment/success
PAYTIKO_FAILED_REDIRECT_URL=https://yourdomain.com/payment/failed

# Optional: Webhook Security Settings
PAYTIKO_VERIFY_WEBHOOK_SIGNATURE=true

# Optional: HTTP Client Settings
PAYTIKO_HTTP_TIMEOUT=30
PAYTIKO_HTTP_CONNECT_TIMEOUT=10
PAYTIKO_HTTP_VERIFY_SSL=true

# Optional: Logging Settings
PAYTIKO_LOGGING_ENABLED=true
PAYTIKO_LOG_CHANNEL=default
PAYTIKO_LOG_LEVEL=info

# Optional: Default Currency
PAYTIKO_DEFAULT_CURRENCY=USD
```

## Usage Examples

### Using the Service Directly

```php
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;

// Inject the service
public function __construct(
    private readonly PaytikoWebhookResyncService $resyncService
) {}

// Resync specific orders
$result = $this->resyncService->resyncWebhooks(['order-123', 'order-456']);

if ($result['success']) {
    echo "Resynced {$result['resynced_count']} webhooks";
} else {
    echo "Error: {$result['error']}";
}

// Resync by date range
$result = $this->resyncService->resyncWebhooksByDateRange(
    '2024-01-01 00:00:00',
    '2024-01-31 23:59:59',
    ['SALE', 'REFUND']
);

// Check resync status
$status = $this->resyncService->getResyncStatus('resync-id-123');
```

### Using HTTP Requests

```php
use Illuminate\Support\Facades\Http;

// Resync webhooks
$response = Http::post('/api/webhooks/paytiko/resync', [
    'order_ids' => ['order-123', 'order-456'],
]);

// Resync by date range
$response = Http::post('/api/webhooks/paytiko/resync-by-date', [
    'start_date' => '2024-01-01 00:00:00',
    'end_date' => '2024-01-31 23:59:59',
    'transaction_types' => ['SALE'],
]);

// Check status
$response = Http::get('/api/webhooks/paytiko/resync-status/resync-123');
```

## Error Handling

The API returns appropriate HTTP status codes and error messages:

- **400 Bad Request:** Invalid parameters or Paytiko API error
- **422 Unprocessable Entity:** Validation errors
- **500 Internal Server Error:** Network errors or unexpected exceptions

Example error response:

```json
{
    "error": "Validation failed",
    "errors": {
        "order_ids": ["The order ids field is required."]
    }
}
```

## Events

The resync functionality integrates with the existing event system. When webhooks are resynced and processed, the following events are dispatched:

- `PaytikoWebhookReceived` - Dispatched for all resynced webhooks
- `PaytikoPaymentSuccessful` - For successful payment webhooks
- `PaytikoPaymentFailed` - For failed payment webhooks
- `PaytikoRefundProcessed` - For refund webhooks

## Security

- All resync requests are authenticated using the merchant secret key
- Webhook signature verification is applied to resynced webhooks (if enabled)
- All requests and responses are logged for audit purposes

## Testing

The package includes comprehensive tests for the webhook resync functionality:

```bash
# Run all tests
./vendor/bin/pest

# Run specific test files
./vendor/bin/pest tests/Unit/PaytikoWebhookResyncServiceTest.php
./vendor/bin/pest tests/Feature/PaytikoWebhookResyncTest.php
```

## Limitations

- This implementation assumes the Paytiko API endpoints follow RESTful conventions
- The actual request/response formats should be verified with official Paytiko documentation
- Rate limiting may apply to resync requests
- Large date ranges may require pagination (not implemented in this version)
- Authentication method (X-Merchant-Secret header) should be confirmed with Paytiko

## Support

For issues related to the webhook resync functionality:

1. Check the Laravel logs for detailed error messages
2. Verify your Paytiko configuration
3. Ensure your merchant secret key is correct
4. Contact Paytiko support for API-specific issues
