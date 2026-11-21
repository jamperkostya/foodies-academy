<?php

namespace Tests\Unit;

use App\Support\Units;
use Tests\TestCase;

class UnitsTest extends TestCase
{
    public function test_russian_spellings_get_a_code(): void
    {
        $this->assertSame('g', Units::code('гр  '));
        $this->assertSame('tbsp', Units::code('Ст. л.'));
        $this->assertSame('tbsp', Units::code('cт.л.')); // a Latin "c"
        // Typos are fixed in the recipes (recipes:fix-ingredients), not known here.
        $this->assertNull(Units::code('ст.л.ё'));
        $this->assertSame('glass', Units::code('стакана'));
        $this->assertSame('to_taste', Units::code('по вкусу'));
        $this->assertSame('', Units::code(' '));
        $this->assertNull(Units::code('1/2'));
    }

    public function test_metric_and_uk_turn_cups_into_millilitres_and_keep_the_rest(): void
    {
        $this->assertSame(['quantity' => 375.0, 'unit' => 'ml'], Units::convert(1.5, 'glass', 'metric'));
        $this->assertSame(['quantity' => 240.0, 'unit' => 'ml'], Units::convert(1, 'cup', 'uk'));
        $this->assertSame(['quantity' => 200.0, 'unit' => 'g'], Units::convert(200, 'g', 'uk'));
        $this->assertSame(['quantity' => 2.0, 'unit' => 'tbsp'], Units::convert(2, 'tbsp', 'metric'));
    }

    public function test_us_gets_ounces_pounds_cups_and_inches(): void
    {
        $this->assertSame(['quantity' => 3.5, 'unit' => 'oz'], Units::convert(100, 'g', 'us'));
        $this->assertSame(['quantity' => 2.25, 'unit' => 'lb'], Units::convert(1, 'kg', 'us'));
        // A few grams of a spice stay grams: "0.18 oz" says nothing.
        $this->assertSame(['quantity' => 5.0, 'unit' => 'g'], Units::convert(5, 'g', 'us'));
        $this->assertSame(['quantity' => 1.0, 'unit' => 'cup'], Units::convert(1, 'glass', 'us'));
        $this->assertSame(['quantity' => 1 / 3, 'unit' => 'cup'], Units::convert(80, 'ml', 'us'));
        $this->assertSame(['quantity' => 2.0, 'unit' => 'tbsp'], Units::convert(30, 'ml', 'us'));
        $this->assertSame(['quantity' => 9.5, 'unit' => 'in'], Units::convert(24, 'cm', 'us'));
        // Spoons, pieces and "to taste" stay.
        $this->assertSame(['quantity' => 1.0, 'unit' => 'tsp'], Units::convert(1, 'tsp', 'us'));
        $this->assertSame(['quantity' => 3.0, 'unit' => 'clove'], Units::convert(3, 'clove', 'us'));
        $this->assertSame(['quantity' => null, 'unit' => 'to_taste'], Units::convert(null, 'to_taste', 'us'));
    }

    public function test_labels_and_oven_temperatures(): void
    {
        $this->assertSame('cup', Units::label('glass', 1));
        $this->assertSame('cups', Units::label('glass', 1.5));
        $this->assertSame('', Units::label('piece', 2));

        $this->assertSame('180°C', Units::temperature(180, 'metric'));
        $this->assertSame('180°C (160°C fan, gas 4)', Units::temperature(180, 'uk'));
        $this->assertSame('220°C (200°C fan, gas 7)', Units::temperature(220, 'uk'));
        $this->assertSame('350°F', Units::temperature(180, 'us'));
        $this->assertSame('425°F', Units::temperature(220, 'us'));
    }
}
