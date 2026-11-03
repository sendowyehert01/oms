<?php

namespace App;

use InvalidArgumentException;

class MoneyAmount
{
    public static function inCents(string $amount): int
    {
        if (! preg_match('/^\d{1,16}(?:\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException('Enter a monetary amount with up to two decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
