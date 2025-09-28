<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\DataObjects;

readonly class PaytikoBillingDetails
{
    public function __construct(
        public string $firstName,
        public string $email,
        public string $country,
        public string $phone,
        public string $currency,
        public ?int $lockedAmount = null,
        public ?string $lastName = null,
        public ?string $street = null,
        public ?string $region = null,
        public ?string $city = null,
        public ?string $zipCode = null,
        public ?string $dateOfBirth = null,
        public ?string $gender = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'street' => $this->street,
            'region' => $this->region,
            'city' => $this->city,
            'country' => $this->country,
            'zipCode' => $this->zipCode,
            'phone' => $this->phone,
            'dateOfBirth' => $this->dateOfBirth,
            'gender' => $this->gender,
            'currency' => $this->currency,
            'lockedAmount' => $this->lockedAmount,
        ], fn($value) => $value !== null);
    }
}
