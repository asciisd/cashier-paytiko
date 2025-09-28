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
                    amount: (int) ($validatedData['amount'] * 100), // Convert dollars to cents for internal storage
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
     * Generate order ID if not provided
     */
    private function generateOrderId(): string
    {
        return 'paytiko_' . Str::uuid();
    }
}
