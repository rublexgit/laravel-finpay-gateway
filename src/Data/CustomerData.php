<?php

namespace Finpay\Data;

use InvalidArgumentException;

class CustomerData
{
    public function __construct(
        private readonly string $email,
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $mobilePhone
    ) {
        $this->validate();
    }

    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'mobilePhone' => $this->mobilePhone,
        ];
    }

    private function validate(): void
    {
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Customer email is invalid.');
        }

        if ($this->firstName === '' || $this->lastName === '' || $this->mobilePhone === '') {
            throw new InvalidArgumentException('Customer firstName, lastName and mobilePhone are required.');
        }
    }
}
