<?php

namespace App\Support;

/**
 * Units of recipe ingredients (config/units.php): the code of a Russian spelling, a quantity
 * converted for a reader's unit system, English labels, oven temperatures.
 */
class Units
{
    /** Code of a unit as written in WP Ultimate Recipe ("ст. л." → "tbsp"), null if unknown, "" if empty. */
    public static function code(string $unit): ?string
    {
        $key = self::normalize($unit);

        return $key === '' ? '' : (config("units.aliases.$key") ?? null);
    }

    /** The form aliases are compared in: lowercased, no spaces or dots, "ё" as "е", a Latin "c" as Cyrillic. */
    public static function normalize(string $unit): string
    {
        return strtr(mb_strtolower(preg_replace('/[\s.]+/u', '', $unit)), ['ё' => 'е', 'c' => 'с']);
    }

    /**
     * $quantity of $code in the units of $system (metric, uk, us), rounded the way recipes write it.
     * Spoons, counted units and units without a quantity stay as they are.
     *
     * @return array{quantity: float|null, unit: string}
     */
    public static function convert(?float $quantity, string $code, string $system): array
    {
        $unit = config("units.units.$code");
        $same = ['quantity' => $quantity, 'unit' => $code];
        if ($quantity === null || ! $unit || ! in_array($unit['type'], ['mass', 'volume', 'length'], true) || in_array($code, ['tsp', 'tbsp'], true)) {
            return $same;
        }
        $base = $quantity * $unit['base'];

        if ($system === 'us') {
            return match ($unit['type']) {
                // A few grams of a spice say more than "0.18 oz"; without its density a weight can't
                // become spoons or cups.
                'mass' => $base < 15 ? $same : ($base >= 450
                    ? ['quantity' => self::nice($base / 453.592, 4), 'unit' => 'lb']
                    : ['quantity' => self::nice($base / 28.3495, $base < 113 ? 4 : 2), 'unit' => 'oz']),
                'volume' => match (true) {
                    $base < 15 => ['quantity' => self::nice($base / 5, 4), 'unit' => 'tsp'],
                    $base < 60 => ['quantity' => self::nice($base / 15, 2), 'unit' => 'tbsp'],
                    default => ['quantity' => self::nice($base / 240, 4), 'unit' => 'cup'],
                },
                'length' => ['quantity' => self::nice($base / 2.54, 2), 'unit' => 'in'],
            };
        }

        // Metric and UK: cups become millilitres, the rest is metric already.
        return in_array($code, ['glass', 'cup'], true)
            ? ['quantity' => round($base / 5) * 5, 'unit' => 'ml']
            : $same;
    }

    /** English label of $code for $quantity: "1 cup", "2 cups"; "" for a counted piece ("2 eggs"). */
    public static function label(string $code, ?float $quantity): string
    {
        [$one, $many] = config("units.units.$code.en", [$code, $code]);

        return $quantity !== null && $quantity > 1 ? $many : $one;
    }

    /** An oven temperature: "180°C", "180°C (160°C fan, gas 4)" in the UK, "350°F" in the US. */
    public static function temperature(int $celsius, string $system): string
    {
        if ($system === 'us') {
            return (int) (round(($celsius * 9 / 5 + 32) / 25) * 25).'°F';
        }
        if ($system !== 'uk' || $celsius < 120) {
            return "{$celsius}°C";
        }
        // Gas marks 1–9 are 140–240 °C; a fan oven needs 20 °C less.
        $gas = $celsius < 140 ? '½' : (string) min(9, max(1, (int) round(($celsius - 140) / 12.5) + 1));

        return "{$celsius}°C (".($celsius - 20)."°C fan, gas $gas)";
    }

    /** $value rounded to 1/$per or to thirds, whichever is closer: 0.33 → 1/3, 0.24 → 1/4. */
    private static function nice(float $value, int $per): float
    {
        $quarter = round($value * $per) / $per;
        $third = round($value * 3) / 3;
        $best = abs($value - $third) < abs($value - $quarter) - 0.01 ? $third : $quarter;

        return $best > 0 ? $best : 1 / $per;
    }
}
