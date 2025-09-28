<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Services;

use Asciisd\CashierCore\DataObjects\PaymentMethodSnapshot;
use Asciisd\CashierCore\Enums\PaymentStatus;
use Asciisd\CashierCore\Models\Transaction;
use Asciisd\CashierPaytiko\DataObjects\PaytikoWebhookData;
use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class PaytikoWebhookResyncService
{
    public function __construct(
        private readonly Client $httpClient,
        private readonly PaytikoSignatureService $signatureService,
        private readonly string $coreUrl,
        private readonly string $merchantSecretKey,
    ) {}

    /**
     * Request webhook resync for specific order IDs
     * Note: Paytiko API only supports single order resync per request
     */
    public function resyncWebhooks(array $orderIds, ?string $startDate = null, ?string $endDate = null): array
    {
        $results = [];
        $successCount = 0;
        $errors = [];

        foreach ($orderIds as $orderId) {
            $result = $this->resyncSingleWebhook($orderId);
            $results[] = $result;
            
            if ($result['success']) {
                $successCount++;
            } else {
                $errors[] = $result['error'];
            }
        }

        if (config('cashier-paytiko.logging.enabled', true)) {
            Log::info('Paytiko webhook resync batch completed', [
                'order_ids' => $orderIds,
                'success_count' => $successCount,
                'total_count' => count($orderIds),
                'errors' => $errors,
            ]);
        }

        return [
            'success' => $successCount > 0,
            'resynced_count' => $successCount,
            'message' => $successCount > 0 
                ? "Successfully resynced {$successCount} out of " . count($orderIds) . " webhooks"
                : 'No webhooks were resynced',
            'resynced_orders' => array_filter($orderIds, function($orderId, $index) use ($results) {
                return $results[$index]['success'] ?? false;
            }, ARRAY_FILTER_USE_BOTH),
            'errors' => $errors,
        ];
    }

    /**
     * Request webhook resync for a single order ID
     */
    public function resyncSingleWebhook(string $orderId): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Merchant-Secret' => $this->merchantSecretKey,
            ];

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook resync request initiated', [
                    'order_id' => $orderId,
                ]);
            }

            $response = $this->httpClient->post(
                $this->coreUrl . '/api/webhook-resync?merchantOrderId=' . urlencode($orderId),
                [
                    'headers' => $headers,
                    'timeout' => config('cashier-paytiko.http.timeout', 30),
                    'connect_timeout' => config('cashier-paytiko.http.connect_timeout', 10),
                    'verify' => config('cashier-paytiko.http.verify', true),
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            // Store the processor response in the transaction record
            $this->storeProcessorResponse($orderId, $data, 'resync');

            // If resync was successful, extract the payload and update transaction status
            if ($data['isSuccess'] ?? false) {
                $this->processResyncSuccess($orderId);
            }

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook resync completed successfully', [
                    'order_id' => $orderId,
                    'response' => $data,
                ]);
            }

            return [
                'success' => $data['isSuccess'] ?? false,
                'message' => $data['isSuccess'] 
                    ? 'Webhook resynced successfully' 
                    : ($data['errorMessage'] ?? 'Unknown error'),
                'order_id' => $orderId,
            ];

        } catch (GuzzleException $e) {
            $errorData = null;
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $message = $errorData['errorMessage'] ?? $errorData['title'] ?? $message;
            }

            // Store the error response in the transaction record
            $this->storeProcessorResponse($orderId, $errorData ?? ['error' => $message], 'resync_error');

            Log::error('Paytiko webhook resync failed', [
                'order_id' => $orderId,
                'error' => $message,
                'error_data' => $errorData,
            ]);

            return [
                'success' => false,
                'error' => $message,
                'error_data' => $errorData,
                'order_id' => $orderId,
            ];
        }
    }

    /**
     * Request webhook resync for a date range
     */
    public function resyncWebhooksByDateRange(string $startDate, string $endDate, ?array $transactionTypes = null): array
    {
        try {
            $requestData = [
                'startDate' => $startDate,
                'endDate' => $endDate,
            ];

            if ($transactionTypes) {
                $requestData['transactionTypes'] = $transactionTypes;
            }

            $headers = [
                'Content-Type' => 'application/json',
                'X-Merchant-Secret' => $this->merchantSecretKey,
            ];

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook resync by date range initiated', [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'transaction_types' => $transactionTypes,
                ]);
            }

            $response = $this->httpClient->post(
                $this->coreUrl . '/api/webhook/resync-by-date',
                [
                    'headers' => $headers,
                    'json' => $requestData,
                    'timeout' => config('cashier-paytiko.http.timeout', 30),
                    'connect_timeout' => config('cashier-paytiko.http.connect_timeout', 10),
                    'verify' => config('cashier-paytiko.http.verify', true),
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook resync by date range completed successfully', [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'resynced_count' => $data['resyncedCount'] ?? 0,
                ]);
            }

            return [
                'success' => true,
                'resynced_count' => $data['resyncedCount'] ?? 0,
                'message' => $data['message'] ?? 'Webhooks resynced successfully',
                'resynced_orders' => $data['resyncedOrders'] ?? [],
            ];

        } catch (GuzzleException $e) {
            $errorData = null;
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $message = $errorData['title'] ?? $message;
            }

            Log::error('Paytiko webhook resync by date range failed', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $message,
                'error_data' => $errorData,
            ]);

            return [
                'success' => false,
                'error' => $message,
                'error_data' => $errorData,
            ];
        }
    }

    /**
     * Get webhook resync status
     */
    public function getResyncStatus(string $resyncId): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Merchant-Secret' => $this->merchantSecretKey,
            ];

            $response = $this->httpClient->get(
                $this->coreUrl . '/api/webhook/resync-status/' . $resyncId,
                [
                    'headers' => $headers,
                    'timeout' => config('cashier-paytiko.http.timeout', 30),
                    'connect_timeout' => config('cashier-paytiko.http.connect_timeout', 10),
                    'verify' => config('cashier-paytiko.http.verify', true),
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'status' => $data['status'] ?? 'unknown',
                'progress' => $data['progress'] ?? 0,
                'total_webhooks' => $data['totalWebhooks'] ?? 0,
                'processed_webhooks' => $data['processedWebhooks'] ?? 0,
                'failed_webhooks' => $data['failedWebhooks'] ?? 0,
            ];

        } catch (GuzzleException $e) {
            $errorData = null;
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $message = $errorData['title'] ?? $message;
            }

            Log::error('Paytiko webhook resync status check failed', [
                'resync_id' => $resyncId,
                'error' => $message,
                'error_data' => $errorData,
            ]);

            return [
                'success' => false,
                'error' => $message,
                'error_data' => $errorData,
            ];
        }
    }

    /**
     * Extract webhook payload for a specific order ID
     */
    public function extractWebhookPayload(string $orderId): array
    {
        try {
            $headers = [
                'Content-Type' => 'application/json',
                'X-Merchant-Secret' => $this->merchantSecretKey,
            ];

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook payload extraction initiated', [
                    'order_id' => $orderId,
                ]);
            }

            $response = $this->httpClient->get(
                $this->coreUrl . '/api/webhook-resync/extract-payload?merchantOrderId=' . urlencode($orderId),
                [
                    'headers' => $headers,
                    'timeout' => config('cashier-paytiko.http.timeout', 30),
                    'connect_timeout' => config('cashier-paytiko.http.connect_timeout', 10),
                    'verify' => config('cashier-paytiko.http.verify', true),
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            // Store the processor response in the transaction record
            $this->storeProcessorResponse($orderId, $data, 'extract_payload');

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook payload extraction completed', [
                    'order_id' => $orderId,
                    'success' => $data['isSuccess'] ?? false,
                ]);
            }

            return [
                'success' => $data['isSuccess'] ?? false,
                'payload' => $data['resultObject'] ?? null,
                'message' => $data['isSuccess'] 
                    ? 'Payload extracted successfully' 
                    : ($data['errorMessage'] ?? 'Unknown error'),
                'order_id' => $orderId,
            ];

        } catch (GuzzleException $e) {
            $errorData = null;
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $message = $errorData['errorMessage'] ?? $errorData['title'] ?? $message;
            }

            // Store the error response in the transaction record
            $this->storeProcessorResponse($orderId, $errorData ?? ['error' => $message], 'extract_payload_error');

            Log::error('Paytiko webhook payload extraction failed', [
                'order_id' => $orderId,
                'error' => $message,
                'error_data' => $errorData,
            ]);

            return [
                'success' => false,
                'error' => $message,
                'error_data' => $errorData,
                'order_id' => $orderId,
            ];
        }
    }

    /**
     * Process successful resync by extracting payload and updating transaction status
     */
    private function processResyncSuccess(string $orderId): void
    {
        try {
            // Extract the webhook payload to get the latest transaction data
            $payloadResult = $this->extractWebhookPayload($orderId);
            
            if ($payloadResult['success'] && isset($payloadResult['payload'])) {
                $payload = $payloadResult['payload'];
                $this->updateTransactionFromPayload($orderId, $payload);
            }
        } catch (\Exception $e) {
            Log::error('Failed to process resync success', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update transaction details from Paytiko payload
     */
    private function updateTransactionFromPayload(string $orderId, array $payload): void
    {
        try {
            // Find the transaction
            $transaction = Transaction::where('processor_name', 'paytiko')
                ->where(function ($query) use ($orderId) {
                    $query->where('processor_transaction_id', $orderId)
                          ->orWhereJsonContains('metadata->order_id', $orderId);
                })
                ->first();

            if (!$transaction) {
                Log::warning('Transaction not found for payload update', [
                    'order_id' => $orderId,
                ]);
                return;
            }

            // Map Paytiko status to our internal status
            $paytikoStatus = $payload['TransactionStatus'] ?? null;
            $internalStatus = $this->mapPaytikoStatusToInternal($paytikoStatus);

            // Prepare update data
            $updateData = [
                'status' => $internalStatus,
            ];

            // Update timestamps based on status
            if ($internalStatus === PaymentStatus::Succeeded) {
                $updateData['processed_at'] = now();
                $updateData['failed_at'] = null;
            } elseif ($internalStatus === PaymentStatus::Failed) {
                $updateData['failed_at'] = now();
                $updateData['error_code'] = $payload['DeclineReasonCode'] ?? null;
                $updateData['error_message'] = $payload['DeclineReasonText'] ?? null;
            }

            // Extract and update payment method snapshot data
            $paymentMethodSnapshot = $this->extractPaymentMethodFromPaytikoPayload($payload);
            if ($paymentMethodSnapshot) {
                $updateData = array_merge($updateData, $paymentMethodSnapshot->toArray());
            }

            // Update metadata with additional payload information
            $existingMetadata = $transaction->metadata ?? [];
            $updatedMetadata = array_merge($existingMetadata, [
                'paytiko_transaction_id' => $payload['TransactionId'] ?? null,
                'external_transaction_id' => $payload['ExternalTransactionId'] ?? null,
                'payment_processor' => $payload['PaymentProcessor'] ?? null,
                'card_type' => $payload['CardType'] ?? null,
                'last_cc_digits' => $payload['LastCcDigits'] ?? null,
                'masked_pan' => $payload['MaskedPan'] ?? null,
                'currency' => $payload['Currency'] ?? null,
                'amount' => $payload['Amount'] ?? null,
                'resync_updated_at' => now()->toISOString(),
            ]);

            $updateData['metadata'] = $updatedMetadata;

            // Update the transaction
            $transaction->update($updateData);

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Transaction updated from resync payload', [
                    'order_id' => $orderId,
                    'transaction_id' => $transaction->id,
                    'old_status' => $transaction->getOriginal('status'),
                    'new_status' => $internalStatus->value,
                    'paytiko_status' => $paytikoStatus,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update transaction from payload', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }

    /**
     * Map Paytiko transaction status to internal PaymentStatus
     */
    private function mapPaytikoStatusToInternal(?string $paytikoStatus): PaymentStatus
    {
        return match (strtolower($paytikoStatus ?? '')) {
            'success', 'approved', 'completed' => PaymentStatus::Succeeded,
            'rejected', 'declined', 'failed' => PaymentStatus::Failed,
            'pending', 'processing' => PaymentStatus::Processing,
            'canceled', 'cancelled' => PaymentStatus::Canceled,
            'requires_action', 'action_required' => PaymentStatus::RequiresAction,
            'requires_capture' => PaymentStatus::RequiresCapture,
            'requires_confirmation' => PaymentStatus::RequiresConfirmation,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * Store processor response in the transaction record
     */
    private function storeProcessorResponse(string $orderId, array $responseData, string $action): void
    {
        try {
            // Find the transaction by processor_transaction_id or metadata order_id
            $transaction = Transaction::where('processor_name', 'paytiko')
                ->where(function ($query) use ($orderId) {
                    $query->where('processor_transaction_id', $orderId)
                          ->orWhereJsonContains('metadata->order_id', $orderId);
                })
                ->first();

            if ($transaction) {
                // Get existing processor_response or initialize as empty array
                $existingResponse = $transaction->processor_response ?? [];
                
                // Add the new response with timestamp and action type
                $existingResponse['webhook_resync'][] = [
                    'action' => $action,
                    'timestamp' => now()->toISOString(),
                    'response' => $responseData,
                ];

                // Update the transaction
                $transaction->update([
                    'processor_response' => $existingResponse,
                ]);

                if (config('cashier-paytiko.logging.enabled', true)) {
                    Log::info('Processor response stored successfully', [
                        'order_id' => $orderId,
                        'transaction_id' => $transaction->id,
                        'action' => $action,
                    ]);
                }
            } else {
                if (config('cashier-paytiko.logging.enabled', true)) {
                    Log::warning('Transaction not found for processor response storage', [
                        'order_id' => $orderId,
                        'action' => $action,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to store processor response', [
                'order_id' => $orderId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process resynced webhook data manually
     */
    public function processResyncedWebhook(array $webhookPayload): bool
    {
        try {
            // Parse webhook data using the same method as the webhook controller
            $accountDetails = new \Asciisd\CashierPaytiko\DataObjects\PaytikoAccountDetails(
                merchantId: (int) $webhookPayload['AccountDetails']['MerchantId'],
                createdDate: $webhookPayload['AccountDetails']['CreatedDate'],
                firstName: $webhookPayload['AccountDetails']['FirstName'],
                lastName: $webhookPayload['AccountDetails']['LastName'],
                email: $webhookPayload['AccountDetails']['Email'],
                currency: $webhookPayload['AccountDetails']['Currency'],
                country: $webhookPayload['AccountDetails']['Country'],
                dob: $webhookPayload['AccountDetails']['Dob'],
                city: $webhookPayload['AccountDetails']['City'],
                zipCode: $webhookPayload['AccountDetails']['ZipCode'],
                region: $webhookPayload['AccountDetails']['Region'],
                street: $webhookPayload['AccountDetails']['Street'],
                phone: $webhookPayload['AccountDetails']['Phone'],
            );

            $webhookData = new PaytikoWebhookData(
                orderId: $webhookPayload['OrderId'],
                accountId: $webhookPayload['AccountId'],
                accountDetails: $accountDetails,
                transactionType: $webhookPayload['TransactionType'],
                transactionStatus: $webhookPayload['TransactionStatus'],
                initialAmount: (float) $webhookPayload['InitialAmount'],
                currency: $webhookPayload['Currency'],
                transactionId: (int) $webhookPayload['TransactionId'],
                externalTransactionId: $webhookPayload['ExternalTransactionId'],
                paymentProcessor: $webhookPayload['PaymentProcessor'],
                issueDate: $webhookPayload['IssueDate'],
                internalPspId: $webhookPayload['InternalPspId'],
                signature: $webhookPayload['Signature'],
                declineReasonText: $webhookPayload['DeclineReasonText'] ?? null,
                cardType: $webhookPayload['CardType'] ?? null,
                lastCcDigits: $webhookPayload['LastCcDigits'] ?? null,
                cascadingInfo: $webhookPayload['CascadingInfo'] ?? null,
                maskedPan: $webhookPayload['MaskedPan'] ?? null,
            );

            // Update transaction with payment method snapshot data if available
            $this->updateTransactionPaymentMethodFromWebhook($webhookData->orderId, $webhookPayload);

            // Fire webhook received event
            PaytikoWebhookReceived::dispatch($webhookData, $webhookPayload);

            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Resynced webhook processed successfully', [
                    'order_id' => $webhookData->orderId,
                    'transaction_id' => $webhookData->transactionId,
                    'status' => $webhookData->transactionStatus,
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to process resynced webhook', [
                'error' => $e->getMessage(),
                'payload' => $webhookPayload,
            ]);

            return false;
        }
    }

    /**
     * Extract payment method snapshot from Paytiko webhook payload
     */
    private function extractPaymentMethodFromPaytikoPayload(array $payload): ?PaymentMethodSnapshot
    {
        try {
            // Get payment method information from the payload
            $paymentProcessor = strtolower($payload['PaymentProcessor'] ?? '');
            $cardType = strtolower($payload['CardType'] ?? '');
            $lastCcDigits = $payload['LastCcDigits'] ?? null;
            $maskedPan = $payload['MaskedPan'] ?? null;

            // Determine payment method based on available data
            if (!empty($cardType) && !empty($lastCcDigits)) {
                // This is a card payment
                return $this->createCardPaymentMethodSnapshot($cardType, $lastCcDigits, $maskedPan);
            }

            if (!empty($paymentProcessor)) {
                // This is a digital wallet or other payment method
                return $this->createDigitalWalletPaymentMethodSnapshot($paymentProcessor);
            }

            // Fallback: try to determine from other payload fields
            if (isset($payload['InternalPspId'])) {
                $pspId = strtolower($payload['InternalPspId']);
                return $this->createPaymentMethodFromPspId($pspId);
            }

            // If we can't determine the payment method, return null
            return null;

        } catch (\Exception $e) {
            Log::warning('Failed to extract payment method from Paytiko payload', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
            return null;
        }
    }

    /**
     * Create card payment method snapshot
     */
    private function createCardPaymentMethodSnapshot(string $cardType, string $lastFour, ?string $maskedPan = null): PaymentMethodSnapshot
    {
        // Map Paytiko card types to our enum values
        $brand = match (strtolower($cardType)) {
            'visa' => 'visa',
            'mastercard', 'master', 'mc' => 'mastercard',
            'amex', 'american express', 'americanexpress' => 'american_express',
            'discover' => 'discover',
            'jcb' => 'jcb',
            'diners', 'diners club', 'dinersclub' => 'diners_club',
            'unionpay', 'union pay' => 'union_pay',
            default => 'visa', // Default fallback
        };

        $displayName = null;
        if ($maskedPan) {
            $displayName = ucfirst($brand) . ' ' . $maskedPan;
        }

        return PaymentMethodSnapshot::fromCardData(
            brand: $brand,
            lastFour: $lastFour,
            displayName: $displayName
        );
    }

    /**
     * Create digital wallet payment method snapshot
     */
    private function createDigitalWalletPaymentMethodSnapshot(string $processor): PaymentMethodSnapshot
    {
        // Map Paytiko payment processors to our enum values
        $brand = match (strtolower($processor)) {
            'fawry' => 'fawry',
            'vodafone', 'vodafone cash' => 'vodafone',
            'orange', 'orange money' => 'orange',
            'etisalat', 'etisalat cash' => 'etisalat',
            'instapay' => 'instapay',
            'valu' => 'valu',
            'binance', 'binance pay' => 'binance_pay',
            'paypal' => 'paypal',
            'apple pay', 'applepay' => 'apple_pay',
            'google pay', 'googlepay' => 'google_pay',
            'samsung pay', 'samsungpay' => 'samsung_pay',
            'alipay' => 'alipay',
            'wechat', 'wechat pay' => 'wechat',
            default => 'other',
        };

        return PaymentMethodSnapshot::fromDigitalWallet($brand);
    }

    /**
     * Create payment method snapshot from PSP ID
     */
    private function createPaymentMethodFromPspId(string $pspId): PaymentMethodSnapshot
    {
        // Map common PSP IDs to payment methods
        $brand = match (true) {
            str_contains($pspId, 'fawry') => 'fawry',
            str_contains($pspId, 'vodafone') => 'vodafone',
            str_contains($pspId, 'orange') => 'orange',
            str_contains($pspId, 'etisalat') => 'etisalat',
            str_contains($pspId, 'instapay') => 'instapay',
            str_contains($pspId, 'valu') => 'valu',
            str_contains($pspId, 'binance') => 'binance_pay',
            str_contains($pspId, 'wire') || str_contains($pspId, 'bank') => 'wire_transfer',
            default => 'other',
        };

        if ($brand === 'wire_transfer') {
            return PaymentMethodSnapshot::fromBankTransfer($brand);
        }

        return PaymentMethodSnapshot::fromDigitalWallet($brand);
    }

    /**
     * Update transaction payment method data from webhook payload
     */
    private function updateTransactionPaymentMethodFromWebhook(string $orderId, array $webhookPayload): void
    {
        try {
            // Find the transaction
            $transaction = Transaction::where('processor_name', 'paytiko')
                ->where(function ($query) use ($orderId) {
                    $query->where('processor_transaction_id', $orderId)
                          ->orWhereJsonContains('metadata->order_id', $orderId);
                })
                ->first();

            if (!$transaction) {
                Log::warning('Transaction not found for payment method update', [
                    'order_id' => $orderId,
                ]);
                return;
            }

            // Extract payment method snapshot from webhook payload
            $paymentMethodSnapshot = $this->extractPaymentMethodFromPaytikoPayload($webhookPayload);
            
            if ($paymentMethodSnapshot) {
                // Only update if we don't already have payment method data or if it's incomplete
                $shouldUpdate = empty($transaction->payment_method_type) || 
                               empty($transaction->payment_method_brand) ||
                               ($paymentMethodSnapshot->lastFour && empty($transaction->payment_method_last_four));

                if ($shouldUpdate) {
                    $transaction->update($paymentMethodSnapshot->toArray());

                    if (config('cashier-paytiko.logging.enabled', true)) {
                        Log::info('Transaction payment method updated from webhook', [
                            'order_id' => $orderId,
                            'transaction_id' => $transaction->id,
                            'payment_method_type' => $paymentMethodSnapshot->type->value,
                            'payment_method_brand' => $paymentMethodSnapshot->brand->value,
                            'payment_method_last_four' => $paymentMethodSnapshot->lastFour,
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to update transaction payment method from webhook', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'webhook_payload' => $webhookPayload,
            ]);
        }
    }
}
