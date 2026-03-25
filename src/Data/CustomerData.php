<?php

declare(strict_types=1);

namespace Finpay\Data;

use Rublex\CoreGateway\Exceptions\ValidationException;

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

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function lastName(): string
    {
        return $this->lastName;
    }

    public function mobilePhone(): string
    {
        return $this->mobilePhone;
    }

    private function validate(): void
    {
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Customer email is invalid.');
        }

        if ($this->firstName === '' || $this->lastName === '' || $this->mobilePhone === '') {
            throw new ValidationException('Customer firstName, lastName and mobilePhone are required.');
        }
    }
}
