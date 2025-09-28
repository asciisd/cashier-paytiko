<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Services;

use Asciisd\CashierPaytiko\DataObjects\PaytikoBillingDetails;
use Asciisd\CashierPaytiko\DataObjects\PaytikoHostedPageRequest;
use Asciisd\CashierPaytiko\DataObjects\PaytikoHostedPageResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class PaytikoHostedPageService
{
    public function __construct(
        private readonly Client $httpClient,
        private readonly PaytikoSignatureService $signatureService,
        private readonly string $coreUrl,
        private readonly string $merchantSecretKey,
    ) {}

    public function createHostedPage(PaytikoHostedPageRequest $request): PaytikoHostedPageResponse
    {
        try {
            $requestData = $request->toArray();
            $headers = [
                'Content-Type' => 'application/json',
                'X-Merchant-Secret' => $this->merchantSecretKey,
            ];
            
            // Debug logging
            Log::info('Paytiko API Request Debug', [
                'url' => $this->coreUrl . '/api/payment/hosted-page',
                'headers' => array_merge($headers, ['X-Merchant-Secret' => '***HIDDEN***']),
                'merchant_secret_length' => strlen($this->merchantSecretKey),
                'request_data' => $requestData,
            ]);
            
            $response = $this->httpClient->post(
                $this->coreUrl . '/api/payment/hosted-page',
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
                Log::info('Paytiko hosted page created successfully', [
                    'order_id' => $request->orderId,
                    'redirect_url' => $data['redirectUrl'] ?? null,
                ]);
            }

            return new PaytikoHostedPageResponse(
                success: true,
                redirectUrl: $data['redirectUrl'] ?? null,
            );

        } catch (GuzzleException $e) {
            $errorData = null;
            $message = $e->getMessage();

            if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($errorBody, true);
                $message = $errorData['title'] ?? $message;
            }

            Log::error('Paytiko hosted page creation failed', [
                'order_id' => $request->orderId,
                'error' => $message,
                'error_data' => $errorData,
            ]);

            return new PaytikoHostedPageResponse(
                success: false,
                errors: $errorData['errors'] ?? null,
                message: $message,
            );
        }
    }

    public function buildHostedPageRequest(array $data): PaytikoHostedPageRequest
    {
        $timestamp = time();
        $email = $data['billing_details']['email'];
        
        $signature = $this->signatureService->generateHostedPageSignature($email, $timestamp);

        $billingDetails = new PaytikoBillingDetails(
            firstName: $data['billing_details']['first_name'],
            email: $email,
            country: $data['billing_details']['country'],
            phone: $data['billing_details']['phone'],
            currency: $data['billing_details']['currency'] ?? config('cashier-paytiko.default_currency', 'USD'),
            lockedAmount: isset($data['billing_details']['locked_amount']) 
                ? (int) $data['billing_details']['locked_amount'] 
                : (isset($data['amount']) ? (int) $data['amount'] : null),
            lastName: $data['billing_details']['last_name'] ?? null,
            street: $data['billing_details']['street'] ?? null,
            region: $data['billing_details']['region'] ?? null,
            city: $data['billing_details']['city'] ?? null,
            zipCode: $data['billing_details']['zip_code'] ?? null,
            dateOfBirth: $data['billing_details']['date_of_birth'] ?? null,
            gender: $data['billing_details']['gender'] ?? null,
        );

        return new PaytikoHostedPageRequest(
            timestamp: $timestamp,
            orderId: $data['order_id'],
            signature: $signature,
            billingDetails: $billingDetails,
            webhookUrl: $data['webhook_url'] ?? config('cashier-paytiko.webhook_url'),
            successRedirectUrl: $data['success_redirect_url'] ?? config('cashier-paytiko.success_redirect_url'),
            failedRedirectUrl: $data['failed_redirect_url'] ?? config('cashier-paytiko.failed_redirect_url'),
            disabledPspIds: $data['disabled_psp_ids'] ?? null,
            creditCardOnly: $data['credit_card_only'] ?? null,
            cashierDescription: $data['description'] ?? null,
            isPayOut: $data['is_pay_out'] ?? null,
        );
    }
}
