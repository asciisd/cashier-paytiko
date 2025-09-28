<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\DataObjects;

readonly class PaytikoHostedPageResponse
{
    public function __construct(
        public bool $success,
        public ?string $redirectUrl = null,
        public ?array $errors = null,
        public ?string $message = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->success;
    }

    public function isFailed(): bool
    {
        return !$this->success;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'redirect_url' => $this->redirectUrl,
            'errors' => $this->errors,
            'message' => $this->message,
        ];
    }
}
