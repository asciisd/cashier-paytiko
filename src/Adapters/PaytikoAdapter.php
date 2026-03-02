<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Adapters;

use Asciisd\CashierCore\Contracts\PaymentAdapterInterface;
use Asciisd\CashierCore\DataObjects\PaymentMethodSnapshot;
use Asciisd\CashierCore\DataObjects\PaymentResult;
use Asciisd\CashierCore\DataObjects\TransactionWebhookUpdate;
use Asciisd\CashierCore\Enums\PaymentMethodBrand;
use Asciisd\CashierCore\Enums\PaymentStatus;

class PaytikoAdapter implements PaymentAdapterInterface
{
    /**
     * Transform Paytiko processor response to PaymentResult.
     */
    public function fromProviderResponse(mixed $response): PaymentResult
    {
        return new PaymentResult(
            success: $response->isSuccessful(),
            transactionId: $response->transactionId,
            status: $this->mapStatus($response->status),
            amount: $response->amount,
            currency: $response->currency,
            message: $response->message,
            metadata: $response->metadata,
            processorResponse: $response->metadata,
        );
    }

    /**
     * Transform Paytiko raw payload (from retrieve/resync) to PaymentResult.
     */
    public function fromProviderPayload(string $transactionId, array $payload): PaymentResult
    {
        $status = $this->mapStatus($payload['TransactionStatus'] ?? 'pending');

        return new PaymentResult(
            success: strtolower($payload['TransactionStatus'] ?? '') === 'success',
            transactionId: $transactionId,
            status: $status,
            amount: $this->extractAmount($payload),
            currency: $payload['Currency'] ?? 'USD',
            message: $this->extractMessage($payload, $status),
            metadata: $this->extractMetadataFromPayload($payload),
            processorResponse: $payload,
            paymentMethodSnapshot: $this->extractPaymentMethodFromPayload($payload),
        );
    }

    /**
     * Transform webhook payload to TransactionWebhookUpdate.
     */
    public function fromWebhook(array $payload): TransactionWebhookUpdate
    {
        $status = $this->mapStatus($payload['status'] ?? $payload['TransactionStatus'] ?? 'pending');

        return new TransactionWebhookUpdate(
            status: $status,
            processorResponse: $payload,
            paymentMethodSnapshot: $this->extractPaymentMethodFromWebhook($payload),
            metadata: $this->extractWebhookMetadata($payload),
            errorMessage: $payload['message'] ?? $payload['DeclineReasonText'] ?? null,
            amount: $this->extractWebhookAmount($payload),
            currency: $payload['currency'] ?? $payload['Currency'] ?? null,
        );
    }

    /**
     * Map Paytiko status to PaymentStatus.
     */
    public function mapStatus(mixed $providerStatus): PaymentStatus
    {
        if ($providerStatus instanceof \BackedEnum) {
            $statusValue = $providerStatus->value;
        } elseif (is_object($providerStatus)) {
            $statusValue = method_exists($providerStatus, '__toString')
                ? (string) $providerStatus
                : 'pending';
        } else {
            $statusValue = (string) $providerStatus;
        }

        return match (strtolower($statusValue)) {
            'pending', 'created' => PaymentStatus::Pending,
            'processing', 'in_progress' => PaymentStatus::Processing,
            'succeeded', 'completed', 'success' => PaymentStatus::Succeeded,
            'failed', 'error', 'rejected', 'declined' => PaymentStatus::Failed,
            'canceled', 'cancelled' => PaymentStatus::Canceled,
            default => PaymentStatus::Pending,
        };
    }

    public function getProviderName(): string
    {
        return 'paytiko';
    }

    /**
     * Extract amount from payload.
     */
    private function extractAmount(array $payload): int
    {
        return (int) ($payload['InitialAmount'] ?? $payload['Amount'] ?? 0);
    }

    /**
     * Extract actual received amount from webhook payload.
     * UsdAmount is the actual amount received (may differ from InitialAmount in partial payments).
     */
    private function extractWebhookAmount(array $payload): int
    {
        return (int) ($payload['UsdAmount'] ?? $payload['usdAmount'] ?? $payload['InitialAmount'] ?? $payload['Amount'] ?? 0);
    }

    private function extractMessage(array $payload, PaymentStatus $status): string
    {
        if (! empty($payload['DeclineReasonText'])) {
            return $payload['DeclineReasonText'];
        }

        return $status->isSuccessful()
            ? 'Transaction retrieved successfully'
            : 'Transaction retrieved';
    }

    private function extractMetadataFromPayload(array $payload): array
    {
        return [
            'paytiko_transaction_id' => $payload['TransactionId'] ?? null,
            'external_transaction_id' => $payload['ExternalTransactionId'] ?? null,
            'payment_processor' => $payload['PaymentProcessor'] ?? null,
            'issue_date' => $payload['IssueDate'] ?? null,
            'internal_psp_id' => $payload['InternalPspId'] ?? null,
        ];
    }

    private function extractWebhookMetadata(array $payload): array
    {
        return [
            'transaction_id' => $payload['transactionId'] ?? $payload['TransactionId'] ?? null,
            'order_id' => $payload['orderId'] ?? $payload['OrderId'] ?? null,
            'external_transaction_id' => $payload['ExternalTransactionId'] ?? null,
            'payment_processor' => $payload['PaymentProcessor'] ?? null,
        ];
    }

    private function extractPaymentMethodFromPayload(array $payload): ?PaymentMethodSnapshot
    {
        if (isset($payload['CardType']) && isset($payload['LastCcDigits'])) {
            return PaymentMethodSnapshot::fromCardData(
                brand: strtolower($payload['CardType']),
                lastFour: $payload['LastCcDigits'],
                displayName: $payload['MaskedPan'] ?? null,
            );
        }

        if (isset($payload['PaymentProcessor'])) {
            return PaymentMethodSnapshot::fromDigitalWallet(
                brand: strtolower($payload['PaymentProcessor']),
            );
        }

        return null;
    }

    private function extractPaymentMethodFromWebhook(array $payload): ?PaymentMethodSnapshot
    {
        if (isset($payload['CardType']) && isset($payload['LastCcDigits'])) {
            return PaymentMethodSnapshot::fromCardData(
                brand: strtolower($payload['CardType']),
                lastFour: $payload['LastCcDigits'],
                displayName: $payload['MaskedPan'] ?? null,
            );
        }

        if (isset($payload['paymentMethod'])) {
            $pm = $payload['paymentMethod'];
            $brand = PaymentMethodBrand::tryFrom($pm['brand'] ?? '') ?? PaymentMethodBrand::Other;

            return new PaymentMethodSnapshot(
                type: $brand->getType(),
                brand: $brand,
                lastFour: $pm['last4'] ?? $pm['lastFour'] ?? null,
                displayName: $pm['displayName'] ?? null,
            );
        }

        if (isset($payload['PaymentProcessor']) && ! isset($payload['CardType'])) {
            return PaymentMethodSnapshot::fromDigitalWallet(
                brand: strtolower($payload['PaymentProcessor']),
            );
        }

        return null;
    }
}
