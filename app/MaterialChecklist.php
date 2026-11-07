<?php

namespace App;

class MaterialChecklist
{
    /** @param list<array{name: string, required_quantity: string|int|float, available_quantity: string|int|float, unit: string}> $materials */
    public static function availability(array $materials): ItemAvailability
    {
        if ($materials === []) {
            return ItemAvailability::NotChecked;
        }

        $allAvailable = true;
        $someAvailable = false;
        foreach ($materials as $material) {
            $required = self::quantityInThousandths($material['required_quantity']);
            $available = self::quantityInThousandths($material['available_quantity']);
            $allAvailable = $allAvailable && $available >= $required;
            $someAvailable = $someAvailable || $available > 0;
        }

        return $allAvailable ? ItemAvailability::Available : ($someAvailable ? ItemAvailability::PartiallyAvailable : ItemAvailability::Unavailable);
    }

    /** @param list<array{name: string, required_quantity: string|int|float, available_quantity: string|int|float, unit: string}> $materials */
    public static function historyNotes(array $materials, ?string $notes): string
    {
        $lines = array_map(fn (array $material): string => $material['name'].': required '.$material['required_quantity'].' '.$material['unit'].', available '.$material['available_quantity'].' '.$material['unit'].'.', $materials);

        return implode("\n", [...$lines, ...($notes === null ? [] : [$notes])]);
    }

    private static function quantityInThousandths(string|int|float $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $quantity, 2), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }
}
