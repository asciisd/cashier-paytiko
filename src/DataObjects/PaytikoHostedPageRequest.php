<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\DataObjects;

readonly class PaytikoHostedPageRequest
{
    public function __construct(
        public int $timestamp,
        public string $orderId,
        public string $signature,
        public PaytikoBillingDetails $billingDetails,
        public ?string $webhookUrl = null,
        public ?string $successRedirectUrl = null,
        public ?string $failedRedirectUrl = null,
        public ?array $disabledPspIds = null,
        public ?bool $creditCardOnly = null,
        public ?string $cashierDescription = null,
        public ?bool $isPayOut = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'timestamp' => $this->timestamp,
            'orderId' => $this->orderId,
            'signature' => $this->signature,
            'billingDetails' => $this->billingDetails->toArray(),
            'webhookUrl' => $this->webhookUrl,
            'successRedirectUrl' => $this->successRedirectUrl,
            'failedRedirectUrl' => $this->failedRedirectUrl,
            'disabledPspIds' => $this->disabledPspIds,
            'creditCardOnly' => $this->creditCardOnly,
            'cashierDescription' => $this->cashierDescription,
            'isPayOut' => $this->isPayOut,
        ], fn($value) => $value !== null);
    }
}
