<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Http\Controllers;

use Asciisd\CashierPaytiko\DataObjects\PaytikoAccountDetails;
use Asciisd\CashierPaytiko\DataObjects\PaytikoWebhookData;
use Asciisd\CashierPaytiko\Events\PaytikoPaymentFailed;
use Asciisd\CashierPaytiko\Events\PaytikoPaymentSuccessful;
use Asciisd\CashierPaytiko\Events\PaytikoRefundProcessed;
use Asciisd\CashierPaytiko\Events\PaytikoWebhookReceived;
use Asciisd\CashierPaytiko\Services\PaytikoSignatureService;
use Asciisd\CashierPaytiko\Services\PaytikoWebhookResyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaytikoWebhookController extends Controller
{
    public function __construct(
        private readonly PaytikoSignatureService $signatureService,
        private readonly PaytikoWebhookResyncService $resyncService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            
            // Verify webhook signature
            if (config('cashier-paytiko.webhook.verify_signature', true)) {
                if (!$this->verifySignature($payload)) {
                    Log::warning('Paytiko webhook signature verification failed', [
                        'payload' => $payload,
                    ]);
                    
                    return response()->json(['error' => 'Invalid signature'], 400);
                }
            }

            // Parse webhook data
            $webhookData = $this->parseWebhookData($payload);
            
            // Fire general webhook received event
            PaytikoWebhookReceived::dispatch($webhookData, $payload);
            
            // Fire specific events based on transaction type and status
            $this->fireSpecificEvents($webhookData);
            
            // Log successful webhook processing
            if (config('cashier-paytiko.logging.enabled', true)) {
                Log::info('Paytiko webhook processed successfully', [
                    'order_id' => $webhookData->orderId,
                    'transaction_id' => $webhookData->transactionId,
                    'status' => $webhookData->transactionStatus,
                    'type' => $webhookData->transactionType,
                ]);
            }
            
            return response()->json(['status' => 'success']);
            
        } catch (\Exception $e) {
            Log::error('Paytiko webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    private function verifySignature(array $payload): bool
    {
        $expectedSignature = $payload['Signature'] ?? '';
        $orderId = $payload['OrderId'] ?? '';
        
        if (empty($expectedSignature) || empty($orderId)) {
            return false;
        }
        
        $calculatedSignature = $this->signatureService->generateWebhookSignature($orderId);
        
        return hash_equals($calculatedSignature, $expectedSignature);
    }

    private function parseWebhookData(array $payload): PaytikoWebhookData
    {
        $accountDetails = new PaytikoAccountDetails(
            merchantId: (int) $payload['AccountDetails']['MerchantId'],
            createdDate: $payload['AccountDetails']['CreatedDate'],
            firstName: $payload['AccountDetails']['FirstName'],
            lastName: $payload['AccountDetails']['LastName'],
            email: $payload['AccountDetails']['Email'],
            currency: $payload['AccountDetails']['Currency'],
            country: $payload['AccountDetails']['Country'],
            dob: $payload['AccountDetails']['Dob'],
            city: $payload['AccountDetails']['City'] ?? null,
            zipCode: $payload['AccountDetails']['ZipCode'] ?? null,
            region: $payload['AccountDetails']['Region'] ?? null,
            street: $payload['AccountDetails']['Street'] ?? null,
            phone: $payload['AccountDetails']['Phone'] ?? null,
        );

        return new PaytikoWebhookData(
            orderId: $payload['OrderId'],
            accountId: $payload['AccountId'],
            accountDetails: $accountDetails,
            transactionType: $payload['TransactionType'],
            transactionStatus: $payload['TransactionStatus'],
            initialAmount: $payload['InitialAmount'],
            currency: $payload['Currency'],
            transactionId: $payload['TransactionId'],
            externalTransactionId: $payload['ExternalTransactionId'],
            paymentProcessor: $payload['PaymentProcessor'],
            issueDate: $payload['IssueDate'],
            internalPspId: $payload['InternalPspId'] ?? null,
            signature: $payload['Signature'],
            declineReasonText: $payload['DeclineReasonText'] ?? null,
            cardType: $payload['CardType'] ?? null,
            lastCcDigits: $payload['LastCcDigits'] ?? null,
            cascadingInfo: $payload['CascadingInfo'] ?? null,
            maskedPan: $payload['MaskedPan'] ?? null,
        );
    }

    private function fireSpecificEvents(PaytikoWebhookData $webhookData): void
    {
        if ($webhookData->isRefund()) {
            PaytikoRefundProcessed::dispatch($webhookData);
            return;
        }

        if ($webhookData->isSuccessful()) {
            PaytikoPaymentSuccessful::dispatch($webhookData);
        } else {
            PaytikoPaymentFailed::dispatch($webhookData);
        }
    }

    /**
     * Resync webhooks for specific order IDs
     */
    public function resyncWebhooks(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'order_ids' => 'required|array|min:1',
                'order_ids.*' => 'required|string',
                'start_date' => 'nullable|date_format:Y-m-d H:i:s',
                'end_date' => 'nullable|date_format:Y-m-d H:i:s',
            ]);

            $result = $this->resyncService->resyncWebhooks(
                $validated['order_ids'],
                $validated['start_date'] ?? null,
                $validated['end_date'] ?? null
            );

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['error'],
                    'error_data' => $result['error_data'] ?? null,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'resynced_count' => $result['resynced_count'],
                'resynced_orders' => $result['resynced_orders'],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Webhook resync request failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Webhook resync failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resync webhooks by date range
     */
    public function resyncWebhooksByDateRange(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after:start_date',
                'transaction_types' => 'nullable|array',
                'transaction_types.*' => 'string|in:SALE,REFUND,CHARGEBACK,VOID',
            ]);

            $result = $this->resyncService->resyncWebhooksByDateRange(
                $validated['start_date'],
                $validated['end_date'],
                $validated['transaction_types'] ?? null
            );

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['error'],
                    'error_data' => $result['error_data'] ?? null,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'resynced_count' => $result['resynced_count'],
                'resynced_orders' => $result['resynced_orders'],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Webhook resync by date range request failed', [
                'error' => $e->getMessage(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Webhook resync failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get webhook resync status
     */
    public function getResyncStatus(Request $request, string $resyncId): JsonResponse
    {
        try {
            $result = $this->resyncService->getResyncStatus($resyncId);

            if (!$result['success']) {
                return response()->json([
                    'error' => $result['error'],
                    'error_data' => $result['error_data'] ?? null,
                ], 400);
            }

            return response()->json([
                'success' => true,
                'status' => $result['status'],
                'progress' => $result['progress'],
                'total_webhooks' => $result['total_webhooks'],
                'processed_webhooks' => $result['processed_webhooks'],
                'failed_webhooks' => $result['failed_webhooks'],
            ]);

        } catch (\Exception $e) {
            Log::error('Webhook resync status check failed', [
                'error' => $e->getMessage(),
                'resync_id' => $resyncId,
            ]);

            return response()->json([
                'error' => 'Failed to get resync status',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process a manually resynced webhook
     */
    public function processResyncedWebhook(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            
            // Verify webhook signature if enabled
            if (config('cashier-paytiko.webhook.verify_signature', true)) {
                if (!$this->verifySignature($payload)) {
                    Log::warning('Resynced webhook signature verification failed', [
                        'payload' => $payload,
                    ]);
                    
                    return response()->json(['error' => 'Invalid signature'], 400);
                }
            }

            $success = $this->resyncService->processResyncedWebhook($payload);

            if (!$success) {
                return response()->json([
                    'error' => 'Failed to process resynced webhook',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Resynced webhook processed successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Resynced webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Resynced webhook processing failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
