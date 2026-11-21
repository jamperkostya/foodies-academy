<?php

/*
 * Units of measure of recipe ingredients.
 *
 * WP Ultimate Recipe keeps the unit of an ingredient as free Russian text ("гр", "ст.л.",
 * "стакана", typos included). Here every spelling found in the site database maps to a code;
 * App\Support\Units converts a quantity in that code to the units a reader expects:
 *  - metric: Russia and continental Europe — grams, millilitres, spoons, centimetres, °C;
 *  - uk: the same metric units (British recipes weigh in grams), ovens in °C with fan and gas mark;
 *  - us: ounces and pounds, US cups, inches, °F.
 * Spellings are compared lowercased, without spaces and dots, "ё" as "е": "Ст. л." is "стл".
 * Typos are not here: recipes:fix-ingredients fixes them in the recipes, and
 * `php artisan units:check` lists the spellings in recipes that aren't here.
 */
return [
    'aliases' => [
        // Mass
        'г' => 'g', 'гр' => 'g', 'грамм' => 'g', 'грамма' => 'g', 'граммов' => 'g',
        'кг' => 'kg', 'килограмм' => 'kg',
        // Volume
        'мл' => 'ml', 'миллилитр' => 'ml', 'миллилитров' => 'ml',
        'л' => 'l', 'литр' => 'l', 'литра' => 'l', 'литров' => 'l',
        'чл' => 'tsp', 'чайнаял' => 'tsp', 'чайнаяложка' => 'tsp', 'чайныеложки' => 'tsp',
        'стл' => 'tbsp', 'столоваял' => 'tbsp', 'столоваяложка' => 'tbsp', 'столовыеложки' => 'tbsp',
        // A Russian "стакан" is 250 ml. "Чашка" comes from translated American recipes: a US cup.
        'стакан' => 'glass', 'стакана' => 'glass', 'стаканов' => 'glass',
        'чашка' => 'cup', 'чашки' => 'cup', 'чашек' => 'cup',
        // Length
        'см' => 'cm', 'м' => 'm',
        // Counted
        'шт' => 'piece', 'штука' => 'piece', 'штуки' => 'piece', 'штук' => 'piece', 'кусок' => 'piece', 'куска' => 'piece', 'кусков' => 'piece',
        'зубчик' => 'clove', 'зубчика' => 'clove', 'зубчиков' => 'clove',
        'щепотка' => 'pinch', 'щепотки' => 'pinch', 'щепоток' => 'pinch',
        'пучок' => 'bunch', 'пучка' => 'bunch', 'пучков' => 'bunch', 'небольшойпучок' => 'bunch',
        'веточка' => 'sprig', 'веточки' => 'sprig', 'веточек' => 'sprig', 'ветка' => 'sprig', 'ветки' => 'sprig',
        'стебель' => 'stalk', 'стебля' => 'stalk', 'стеблей' => 'stalk', 'перо' => 'stalk', 'пера' => 'stalk', 'перьев' => 'stalk',
        'горсть' => 'handful', 'горсти' => 'handful', 'жменя' => 'handful',
        'банка' => 'can', 'банки' => 'can', 'банок' => 'can',
        'головка' => 'head', 'головки' => 'head', 'кочан' => 'head',
        'слайс' => 'slice', 'слайса' => 'slice', 'слайсов' => 'slice', 'ломтик' => 'slice', 'ломтика' => 'slice', 'ломтиков' => 'slice', 'кольцо' => 'slice', 'кольца' => 'slice',
        // A wedge of a tomato or a lemon; garlic comes in cloves (recipes:fix-ingredients).
        'долька' => 'wedge', 'дольки' => 'wedge', 'долек' => 'wedge',
        'лист' => 'leaf', 'листа' => 'leaf', 'листов' => 'leaf', 'листьев' => 'leaf', 'листок' => 'leaf', 'листика' => 'leaf',
        'капля' => 'drop', 'капли' => 'drop', 'капель' => 'drop',
        'кубик' => 'cube', 'кубика' => 'cube', 'кубиков' => 'cube',
        'пакетик' => 'packet', 'пакетика' => 'packet', 'пакет' => 'packet',
        'упаковка' => 'package', 'упаковки' => 'package', 'пачка' => 'package', 'пачки' => 'package',
        'стручок' => 'pod', 'стручка' => 'pod', 'стручек' => 'pod',
        'палочка' => 'stick', 'палочки' => 'stick',
        'филе' => 'fillet',
        'тюбик' => 'tube',
        'бутылка' => 'bottle', 'бутылки' => 'bottle',
        'лимона' => 'lemon', 'лимон' => 'lemon',
        'иголочка' => 'needle', 'иголочки' => 'needle', 'иголочек' => 'needle',
        'волокно' => 'strand', 'волокна' => 'strand',
        'шарик' => 'ball', 'шариков' => 'ball',
        'ребро' => 'rib', 'ребер' => 'rib',
        'горошина' => 'peppercorn', 'горошин' => 'peppercorn',
        'порция' => 'serving', 'порции' => 'serving', 'порций' => 'serving',
        // No quantity
        'повкусу' => 'to_taste', 'поусмотрению' => 'to_taste',
        'понеобходимости' => 'as_needed', 'принеобходимости' => 'as_needed', 'принадобности' => 'as_needed',
    ],

    /*
     * type: mass (base unit g), volume (ml), length (cm), count (as is in every system) or none
     * (no quantity); base: size in the base unit; en: English label, singular and plural.
     */
    'units' => [
        'g' => ['type' => 'mass', 'base' => 1, 'en' => ['g', 'g']],
        'kg' => ['type' => 'mass', 'base' => 1000, 'en' => ['kg', 'kg']],
        'oz' => ['type' => 'mass', 'base' => 28.3495, 'en' => ['oz', 'oz']],
        'lb' => ['type' => 'mass', 'base' => 453.592, 'en' => ['lb', 'lb']],
        'ml' => ['type' => 'volume', 'base' => 1, 'en' => ['ml', 'ml']],
        'l' => ['type' => 'volume', 'base' => 1000, 'en' => ['l', 'l']],
        // Spoons: 15 and 5 ml in Russia, Europe and the UK; the US ones (14.8 and 4.9 ml) are
        // the same in a kitchen, so spoons stay spoons everywhere.
        'tsp' => ['type' => 'volume', 'base' => 5, 'en' => ['tsp', 'tsp']],
        'tbsp' => ['type' => 'volume', 'base' => 15, 'en' => ['tbsp', 'tbsp']],
        'glass' => ['type' => 'volume', 'base' => 250, 'en' => ['cup', 'cups']],
        // The US legal cup used on nutrition labels.
        'cup' => ['type' => 'volume', 'base' => 240, 'en' => ['cup', 'cups']],
        'cm' => ['type' => 'length', 'base' => 1, 'en' => ['cm', 'cm']],
        'm' => ['type' => 'length', 'base' => 100, 'en' => ['m', 'm']],
        'in' => ['type' => 'length', 'base' => 2.54, 'en' => ['inch', 'inches']],
        'piece' => ['type' => 'count', 'en' => ['', '']],
        'clove' => ['type' => 'count', 'en' => ['clove', 'cloves']],
        'pinch' => ['type' => 'count', 'en' => ['pinch', 'pinches']],
        'bunch' => ['type' => 'count', 'en' => ['bunch', 'bunches']],
        'sprig' => ['type' => 'count', 'en' => ['sprig', 'sprigs']],
        'stalk' => ['type' => 'count', 'en' => ['stalk', 'stalks']],
        'handful' => ['type' => 'count', 'en' => ['handful', 'handfuls']],
        'can' => ['type' => 'count', 'en' => ['can', 'cans']],
        'head' => ['type' => 'count', 'en' => ['head', 'heads']],
        'slice' => ['type' => 'count', 'en' => ['slice', 'slices']],
        'wedge' => ['type' => 'count', 'en' => ['wedge', 'wedges']],
        'lemon' => ['type' => 'count', 'en' => ['lemon', 'lemons']],
        'needle' => ['type' => 'count', 'en' => ['needle', 'needles']],
        'leaf' => ['type' => 'count', 'en' => ['leaf', 'leaves']],
        'drop' => ['type' => 'count', 'en' => ['drop', 'drops']],
        'cube' => ['type' => 'count', 'en' => ['cube', 'cubes']],
        'packet' => ['type' => 'count', 'en' => ['packet', 'packets']],
        'package' => ['type' => 'count', 'en' => ['package', 'packages']],
        'pod' => ['type' => 'count', 'en' => ['pod', 'pods']],
        'stick' => ['type' => 'count', 'en' => ['stick', 'sticks']],
        'fillet' => ['type' => 'count', 'en' => ['fillet', 'fillets']],
        'tube' => ['type' => 'count', 'en' => ['tube', 'tubes']],
        'bottle' => ['type' => 'count', 'en' => ['bottle', 'bottles']],
        'strand' => ['type' => 'count', 'en' => ['strand', 'strands']],
        'ball' => ['type' => 'count', 'en' => ['ball', 'balls']],
        'rib' => ['type' => 'count', 'en' => ['rib', 'ribs']],
        'peppercorn' => ['type' => 'count', 'en' => ['peppercorn', 'peppercorns']],
        'serving' => ['type' => 'count', 'en' => ['serving', 'servings']],
        'to_taste' => ['type' => 'none', 'en' => ['to taste', 'to taste']],
        'as_needed' => ['type' => 'none', 'en' => ['as needed', 'as needed']],
    ],

    // Unit systems of the readers, by what they measure with (see the top of the file).
    'systems' => ['metric', 'uk', 'us'],
];
