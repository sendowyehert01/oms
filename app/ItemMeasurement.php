<?php

namespace App;

use InvalidArgumentException;

class ItemMeasurement
{
    private function __construct(public readonly int $hundredths, public readonly string $unit) {}

    public static function fromInput(string|int|float $amount, string $unit): self
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $amount)) {
            throw new InvalidArgumentException('A measurement must be a number with up to two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return new self((int) $whole * 100 + (int) str_pad($fraction, 2, '0'), $unit);
    }

    public static function fromStored(string $value, string $legacyUnit = 'pcs'): self
    {
        [$amount, $unit] = array_pad(explode(' ', trim($value), 2), 2, $legacyUnit);

        return self::fromInput($amount, $unit);
    }

    public function storageValue(): string
    {
        return $this->amount().' '.$this->unit;
    }

    public function displayValue(): string
    {
        [$whole, $fraction] = array_pad(explode('.', $this->amount(), 2), 2, '');
        $formattedWhole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $formattedWhole.($fraction === '' ? '' : '.'.$fraction).' '.$this->unit;
    }

    public function subtotalInCents(int $unitPriceInCents): int
    {
        return intdiv($this->hundredths * $unitPriceInCents + 50, 100);
    }

    private function amount(): string
    {
        $whole = (string) intdiv($this->hundredths, 100);
        $fraction = rtrim(str_pad((string) ($this->hundredths % 100), 2, '0', STR_PAD_LEFT), '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
