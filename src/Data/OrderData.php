<?php

declare(strict_types=1);

namespace Finpay\Data;

use Rublex\CoreGateway\Exceptions\ValidationException;

class OrderData
{
    public function __construct(
        private readonly string $id,
        private readonly string $amount,
        private readonly string $currency,
        private readonly string $description
    ) {
        $this->validate();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return strtoupper($this->currency);
    }

    public function description(): string
    {
        return $this->description;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => strtoupper($this->currency),
            'description' => $this->description,
        ];
    }

    private function validate(): void
    {
        if ($this->id === '' || $this->description === '') {
            throw new ValidationException('Order id and description are required.');
        }

        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new ValidationException('Order amount must be a valid positive number.');
        }

        if ($this->currency === '') {
            throw new ValidationException('Order currency is required.');
        }
    }
}
