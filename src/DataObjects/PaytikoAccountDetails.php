<?php

declare(strict_types=1);

namespace Asciisd\CashierPaytiko\DataObjects;

readonly class PaytikoAccountDetails
{
    public function __construct(
        public int $merchantId,
        public string $createdDate,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $currency,
        public string $country,
        public string $dob,
        public ?string $city,
        public ?string $zipCode,
        public ?string $region,
        public ?string $street,
        public ?string $phone,
    ) {}

    public function toArray(): array
    {
        return [
            'merchant_id' => $this->merchantId,
            'created_date' => $this->createdDate,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'currency' => $this->currency,
            'country' => $this->country,
            'dob' => $this->dob,
            'city' => $this->city,
            'zip_code' => $this->zipCode,
            'region' => $this->region,
            'street' => $this->street,
            'phone' => $this->phone,
        ];
    }
}
