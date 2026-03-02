## Cashier Paytiko (asciisd/cashier-paytiko)

Paytiko payment processor implementation for `asciisd/cashier-core`. Provides hosted page payment flows, webhook handling, signature verification, and webhook resync capabilities via the Paytiko gateway.

### Key Classes

- `PaytikoProcessor` — Extends `AbstractPaymentProcessor`. Supports `charge`, `hosted_page`, `webhook` features. Creates hosted page redirects for payments; refunds are handled via Paytiko admin panel.
- `PaytikoAdapter` — Implements `PaymentAdapterInterface`. Maps Paytiko responses/webhooks to cashier-core DTOs (`PaymentResult`, `TransactionWebhookUpdate`).
- `PaytikoHostedPageService` — Creates hosted payment pages via Paytiko API (`POST /api/payment/hosted-page`).
- `PaytikoSignatureService` — Generates SHA256 signatures for hosted pages and webhook verification.
- `PaytikoWebhookResyncService` — Resyncs missed webhooks by order ID or date range, extracts payloads, and updates transactions.
- `PaytikoWebhookController` — Handles incoming webhooks, verifies signatures, parses data, and dispatches events.

### Charging (Hosted Page Flow)

Paytiko uses a hosted page redirect model. `charge()` creates a hosted page and returns a `PaymentResult` with `Pending` status and a `redirect_url` in metadata. The actual payment result arrives via webhook.

@verbatim
<code-snippet name="Simple Charge" lang="php">
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
</code-snippet>
@endverbatim

### Validation Rules

The `charge()` method requires: `amount` (numeric), `order_id` (string), `billing_details` (array with `first_name`, `email`, `country`, `phone`). Optional fields: `currency`, `description`, `webhook_url`, `success_redirect_url`, `failed_redirect_url`, `disabled_psp_ids`, `credit_card_only`, `is_pay_out`, `fixed_pp_id`.

### Events

- `PaytikoPaymentSuccessful` — Dispatched on successful payment webhook.
- `PaytikoPaymentFailed` — Dispatched on failed/rejected payment webhook.
- `PaytikoRefundProcessed` — Dispatched on refund webhook.
- `PaytikoWebhookReceived` — Dispatched on every webhook (raw payload + parsed `PaytikoWebhookData`).

### Webhook Routes (auto-registered)

| Route | Method | Name |
|-------|--------|------|
| `api/webhooks/paytiko` | POST | `paytiko.webhook` |
| `api/webhooks/paytiko/resync` | POST | `paytiko.webhook.resync` |
| `api/webhooks/paytiko/resync-by-date` | POST | `paytiko.webhook.resync-by-date` |
| `api/webhooks/paytiko/resync-status/{resyncId}` | GET | `paytiko.webhook.resync-status` |
| `api/webhooks/paytiko/process-resynced` | POST | `paytiko.webhook.process-resynced` |

### Status Mapping

| Paytiko Status | PaymentStatus |
|----------------|---------------|
| `success`, `approved`, `completed` | `Succeeded` |
| `failed`, `error`, `rejected`, `declined` | `Failed` |
| `pending`, `created` | `Pending` |
| `processing`, `in_progress` | `Processing` |
| `canceled`, `cancelled` | `Canceled` |

### Config

Config file: `config/cashier-paytiko.php`. Key values: `merchant_secret_key`, `core_url` (UAT: `uat-core.paytiko.com`, prod: `core.paytiko.com`), `webhook_url`, `success_redirect_url`, `failed_redirect_url`, `default_currency`, `webhook.verify_signature`, `http` (timeout, connect_timeout, verify), `logging`.

### Limitations

- `refund()` throws `BadMethodCallException` — refunds are processed through Paytiko admin panel.
- `getPaymentStatus()` throws `BadMethodCallException` — status updates arrive via webhooks only.
- `retrieve()` uses the resync service to fetch transaction data from Paytiko API.
