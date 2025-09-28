<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\DataObjects;

readonly class PaytikoWebhookData
{
    public function __construct(
        public string $orderId,
        public string $accountId,
        public PaytikoAccountDetails $accountDetails,
        public string $transactionType,
        public string $transactionStatus,
        public float $initialAmount,
        public string $currency,
        public int $transactionId,
        public string $externalTransactionId,
        public string $paymentProcessor,
        public string $issueDate,
        public ?string $internalPspId,
        public string $signature,
        public ?string $declineReasonText = null,
        public ?string $cardType = null,
        public ?string $lastCcDigits = null,
        public ?array $cascadingInfo = null,
        public ?string $maskedPan = null,
    ) {}

    public function isSuccessful(): bool
    {
        return strtolower($this->transactionStatus) === 'success';
    }

    public function isRejected(): bool
    {
        return strtolower($this->transactionStatus) === 'rejected';
    }

    public function isFailed(): bool
    {
        return strtolower($this->transactionStatus) === 'failed';
    }

    public function isPayIn(): bool
    {
        return strtolower($this->transactionType) === 'payin';
    }

    public function isPayOut(): bool
    {
        return strtolower($this->transactionType) === 'payout';
    }

    public function isRefund(): bool
    {
        return strtolower($this->transactionType) === 'refund';
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'account_id' => $this->accountId,
            'account_details' => $this->accountDetails->toArray(),
            'transaction_type' => $this->transactionType,
            'transaction_status' => $this->transactionStatus,
            'initial_amount' => $this->initialAmount,
            'currency' => $this->currency,
            'transaction_id' => $this->transactionId,
            'external_transaction_id' => $this->externalTransactionId,
            'payment_processor' => $this->paymentProcessor,
            'decline_reason_text' => $this->declineReasonText,
            'card_type' => $this->cardType,
            'last_cc_digits' => $this->lastCcDigits,
            'cascading_info' => $this->cascadingInfo,
            'issue_date' => $this->issueDate,
            'internal_psp_id' => $this->internalPspId,
            'masked_pan' => $this->maskedPan,
            'signature' => $this->signature,
        ];
    }
}
