<?php

namespace Finpay\Data;

use InvalidArgumentException;

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
            throw new InvalidArgumentException('Order id and description are required.');
        }

        if (!is_numeric($this->amount) || (float) $this->amount <= 0.0) {
            throw new InvalidArgumentException('Order amount must be a valid positive number.');
        }

        if ($this->currency === '') {
            throw new InvalidArgumentException('Order currency is required.');
        }
    }
}
