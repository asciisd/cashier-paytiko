<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko;

use Asciisd\CashierCore\Abstracts\AbstractPaymentProcessor;
use Asciisd\CashierCore\DataObjects\PaymentResult;
use Asciisd\CashierCore\DataObjects\RefundResult;
use Asciisd\CashierCore\Enums\PaymentStatus;
use Asciisd\CashierCore\Enums\RefundStatus;
use Asciisd\CashierCore\Exceptions\InvalidPaymentDataException;
use Asciisd\CashierCore\Exceptions\PaymentProcessingException;
use Asciisd\CashierPaytiko\Services\PaytikoHostedPageService;
use Asciisd\CashierPaytiko\Services\PaytikoSignatureService;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class PaytikoProcessor extends AbstractPaymentProcessor
{
    protected array $supportedFeatures = ['charge', 'hosted_page'];

    private PaytikoHostedPageService $hostedPageService;
    private PaytikoSignatureService $signatureService;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        
        $this->signatureService = new PaytikoSignatureService(
            $this->getConfig('merchant_secret_key')
        );
        
        $this->hostedPageService = new PaytikoHostedPageService(
            new Client(),
            $this->signatureService,
            $this->getConfig('core_url'),
            $this->getConfig('merchant_secret_key')
        );
    }

    public function getName(): string
    {
        return 'paytiko';
    }

    /**
     * Simplified charge method - accepts amount and optional parameters
     */
    public function simpleCharge(int|float $amount, array $params = []): PaymentResult
    {
        // Build payment data from config and parameters
        $paymentData = $this->buildPaymentData($amount, $params);
        
        // Call the full charge method with complete data
        return $this->charge($paymentData);
    }

    /**
     * Standard charge method (inherited from AbstractPaymentProcessor)
     */
    public function charge(array $data): PaymentResult
    {
        $validatedData = $this->validatePaymentData($data);
        
        try {
            // For Paytiko, charge method creates a hosted page redirect
            $hostedPageRequest = $this->hostedPageService->buildHostedPageRequest($validatedData);
            $hostedPageResponse = $this->hostedPageService->createHostedPage($hostedPageRequest);
            
            if ($hostedPageResponse->isSuccessful()) {
                return new PaymentResult(
                    success: true, // This indicates the hosted page creation was successful
                    transactionId: $validatedData['order_id'],
                    amount: (int) $validatedData['amount'], // Use amount as-is (no cents conversion)
                    currency: $validatedData['currency'] ?? config('cashier-paytiko.default_currency', 'USD'),
                    status: PaymentStatus::Pending, // Payment is pending until webhook confirms
                    message: 'Hosted page created successfully',
                    metadata: [
                        'redirect_url' => $hostedPageResponse->redirectUrl,
                        'payment_method' => 'hosted_page',
                    ]
                );
            }
            
            throw new PaymentProcessingException(
                message: $hostedPageResponse->message ?? 'Failed to create hosted page',
                code: 0,
                previous: null,
                transactionId: $validatedData['order_id']
            );
            
        } catch (PaymentProcessingException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PaymentProcessingException(
                message: 'Payment processing failed: ' . $e->getMessage(),
                code: 0,
                previous: $e,
                transactionId: $validatedData['order_id'] ?? null
            );
        }
    }

    public function refund(string $transactionId, ?int $amount = null): RefundResult
    {
        // Paytiko refunds are typically handled through their admin panel
        // or via webhook notifications, not through direct API calls
        throw new \BadMethodCallException('Direct refunds not supported. Refunds are processed via Paytiko admin panel.');
    }

    public function getPaymentStatus(string $transactionId): string
    {
        // Status updates are received via webhooks
        throw new \BadMethodCallException('Payment status retrieval not supported. Status updates are received via webhooks.');
    }

    protected function getValidationRules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'order_id' => 'required|string',
            'description' => 'sometimes|string|max:1024',
            'billing_details' => 'required|array',
            'billing_details.first_name' => 'required|string|max:255',
            'billing_details.email' => 'required|email|max:255',
            'billing_details.country' => 'required|string|size:2',
            'billing_details.phone' => 'required|string|max:20',
            'billing_details.last_name' => 'sometimes|string|max:255',
            'billing_details.street' => 'sometimes|string|max:255',
            'billing_details.region' => 'sometimes|string|max:255',
            'billing_details.city' => 'sometimes|string|max:255',
            'billing_details.zip_code' => 'sometimes|string|max:20',
            'billing_details.date_of_birth' => 'sometimes|date_format:Y-m-d',
            'billing_details.gender' => 'sometimes|in:Male,Female',
            'billing_details.currency' => 'sometimes|string|size:3',
            'billing_details.locked_amount' => 'sometimes|integer|min:1',
            'webhook_url' => 'sometimes|url',
            'success_redirect_url' => 'sometimes|url',
            'failed_redirect_url' => 'sometimes|url',
            'disabled_psp_ids' => 'sometimes|array',
            'credit_card_only' => 'sometimes|boolean',
            'is_pay_out' => 'sometimes|boolean',
        ];
    }

    /**
     * Create a hosted page for payment processing
     */
    public function createHostedPage(array $data): PaymentResult
    {
        return $this->charge($data);
    }

    /**
     * Build complete payment data from amount and parameters
     */
    private function buildPaymentData(int|float $amount, array $params): array
    {
        // Start with defaults from config
        $paymentData = [
            'amount' => $amount,
            'currency' => $params['currency'] ?? $this->getConfig('default_currency', 'USD'),
            'order_id' => $params['order_id'] ?? $this->generateOrderId(),
            'description' => $params['description'] ?? $this->generateDefaultDescription($amount),
            
            // URLs from config (with fallbacks to routes if not set)
            'webhook_url' => $this->getConfig('webhook_url') ?? $this->getDefaultWebhookUrl(),
            'success_redirect_url' => $this->getConfig('success_redirect_url') ?? $this->getDefaultSuccessUrl(),
            'failed_redirect_url' => $this->getConfig('failed_redirect_url') ?? $this->getDefaultFailedUrl(),
        ];

        // Add billing details if provided
        if (isset($params['billing_details'])) {
            $paymentData['billing_details'] = $params['billing_details'];
        }

        // Add any additional parameters
        $additionalParams = [
            'disabled_psp_ids', 'credit_card_only', 'is_pay_out', 'metadata'
        ];

        foreach ($additionalParams as $param) {
            if (isset($params[$param])) {
                $paymentData[$param] = $params[$param];
            }
        }

        return $paymentData;
    }

    /**
     * Generate default description for payment
     */
    private function generateDefaultDescription(int|float $amount): string
    {
        $currency = $this->getConfig('default_currency', 'USD');
        return "Payment of {$currency} {$amount} via Paytiko";
    }

    /**
     * Get default webhook URL (fallback to route if config not set)
     */
    private function getDefaultWebhookUrl(): string
    {
        try {
            return route('paytiko.webhook');
        } catch (\Exception) {
            return url('/api/webhooks/paytiko');
        }
    }

    /**
     * Get default success URL (fallback to route if config not set)
     */
    private function getDefaultSuccessUrl(): string
    {
        try {
            return route('payment.success');
        } catch (\Exception) {
            return url('/payment/success');
        }
    }

    /**
     * Get default failed URL (fallback to route if config not set)
     */
    private function getDefaultFailedUrl(): string
    {
        try {
            return route('payment.failed');
        } catch (\Exception) {
            return url('/payment/failed');
        }
    }

    /**
     * Generate order ID if not provided
     */
    private function generateOrderId(): string
    {
        return 'deposit-' . time() . '-' . bin2hex(random_bytes(4));
    }
}
