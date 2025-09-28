<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\Services;

class PaytikoSignatureService
{
    public function __construct(
        private readonly string $merchantSecretKey,
    ) {}

    public function generateHostedPageSignature(string $email, int $timestamp): string
    {
        $rawSignature = "{$email};{$timestamp};{$this->merchantSecretKey}";
        
        return hash('sha256', $rawSignature);
    }

    public function generateWebhookSignature(string $orderId): string
    {
        $rawSignature = "{$this->merchantSecretKey}:{$orderId}";
        
        return hash('sha256', $rawSignature);
    }
}
