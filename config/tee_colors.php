<?php

/*
|--------------------------------------------------------------------------
| Tee colors
|--------------------------------------------------------------------------
| The vocabulary behind App\Support\TeeColor, which turns a printed tee name
| ("Blue", "Blue/White", "Burgundy", "Azul") into a stored hex.
|
| This is data rather than code because it wants tuning as odd tee names turn
| up, and because the frontend renders the same palette — the editor reads it
| from here via the `palette` prop rather than keeping its own copy.
*/

return [

    /*
    | The canonical swatches, in the order the editor renders them.
    |
    | These shades are load-bearing: they are what the already-colored teeboxes
    | in the database use, and what a scan now snaps to. Re-picking one silently
    | splits a course's tees between the old shade and the new one.
    */
    'palette' => [
        ['name' => 'Black', 'color' => '#111827'],
        ['name' => 'Blue', 'color' => '#1D4ED8'],
        ['name' => 'White', 'color' => '#E5E7EB'],
        ['name' => 'Gold', 'color' => '#CA8A04'],
        ['name' => 'Green', 'color' => '#15803D'],
        ['name' => 'Red', 'color' => '#B91C1C'],
        ['name' => 'Silver', 'color' => '#9CA3AF'],
        ['name' => 'Purple', 'color' => '#7E22CE'],
        ['name' => 'Orange', 'color' => '#EA580C'],
        ['name' => 'Yellow', 'color' => '#EAB308'],
    ],

    /*
    | Color words with no palette swatch. Roughly 1.5% of teeboxes, but they are
    | exactly the ones that used to need a hand-picked hex — burgundy, tan,
    | copper, bronze.
    */
    'extended' => [
        'burgundy' => '#800020',
        'maroon' => '#800000',
        'wine' => '#722F37',
        'crimson' => '#DC143C',
        'ruby' => '#E0115F',
        'rust' => '#B7410E',
        'coral' => '#FF7F50',
        'salmon' => '#FA8072',
        'peach' => '#FFE5B4',
        'pink' => '#FFC0CB',
        'magenta' => '#FF00FF',
        'plum' => '#8E4585',
        'lavender' => '#E6E6FA',
        'indigo' => '#4B0082',
        'navy' => '#000080',
        'royal' => '#4169E1',
        'sky' => '#87CEEB',
        'teal' => '#008080',
        'turquoise' => '#40E0D0',
        'aqua' => '#00FFFF',
        'cyan' => '#00FFFF',
        'jade' => '#00A86B',
        'emerald' => '#50C878',
        'forest' => '#228B22',
        'sage' => '#9CAF88',
        'mint' => '#98FF98',
        'olive' => '#808000',
        'lime' => '#32CD32',
        'amber' => '#FFBF00',
        'mustard' => '#FFDB58',
        'tan' => '#D2B48C',
        'beige' => '#F5F5DC',
        'khaki' => '#F0E68C',
        'sand' => '#C2B280',
        'cream' => '#FFFDD0',
        'ivory' => '#FFFFF0',
        'pearl' => '#EAE0C8',
        'brown' => '#A52A2A',
        'chocolate' => '#D2691E',
        'bronze' => '#CD7F32',
        'copper' => '#B87333',
        'platinum' => '#E5E4E2',
        'pewter' => '#96A8A1',
        'slate' => '#708090',
        'charcoal' => '#36454F',
        'graphite' => '#383428',
        'onyx' => '#353839',
        'grey' => '#808080',
        'gray' => '#808080',
    ],

    /*
    | Variant spellings and non-English color words, pointing at a palette or
    | extended key. Worth the lines: azul (362), gialli (244), blancas (241),
    | azules (233), blanc (212), bleu (206) and friends account for around two
    | thousand teeboxes that would otherwise resolve to nothing.
    */
    'aliases' => [
        // Spanish
        'azul' => 'blue',
        'azules' => 'blue',
        'blanco' => 'white',
        'blanca' => 'white',
        'blancos' => 'white',
        'blancas' => 'white',
        'rojo' => 'red',
        'roja' => 'red',
        'rojos' => 'red',
        'rojas' => 'red',
        'verde' => 'green',
        'verdes' => 'green',
        'negro' => 'black',
        'negra' => 'black',
        'negros' => 'black',
        'negras' => 'black',
        'amarillo' => 'yellow',
        'amarilla' => 'yellow',
        'amarillos' => 'yellow',
        'amarillas' => 'yellow',
        'dorado' => 'gold',
        'dorada' => 'gold',
        'dorados' => 'gold',
        'doradas' => 'gold',
        'oro' => 'gold',
        'plata' => 'silver',
        'plateado' => 'silver',
        'naranja' => 'orange',
        'morado' => 'purple',
        'morada' => 'purple',

        // French
        'bleu' => 'blue',
        'bleue' => 'blue',
        'bleus' => 'blue',
        'bleues' => 'blue',
        'blanc' => 'white',
        'blanche' => 'white',
        'blanches' => 'white',
        'blancs' => 'white',
        'rouge' => 'red',
        'rouges' => 'red',
        'vert' => 'green',
        'verte' => 'green',
        'verts' => 'green',
        'vertes' => 'green',
        'noir' => 'black',
        'noire' => 'black',
        'noirs' => 'black',
        'jaune' => 'yellow',
        'jaunes' => 'yellow',
        'or' => 'gold',
        'dore' => 'gold',
        'argent' => 'silver',
        'violet' => 'purple',
        'violette' => 'purple',

        // Italian
        'blu' => 'blue',
        'azzurro' => 'blue',
        'azzurri' => 'blue',
        'bianco' => 'white',
        'bianchi' => 'white',
        'bianche' => 'white',
        'rosso' => 'red',
        'rossi' => 'red',
        'rossa' => 'red',
        'rosse' => 'red',
        'verdi' => 'green',
        'nero' => 'black',
        'neri' => 'black',
        'nera' => 'black',
        'giallo' => 'yellow',
        'gialli' => 'yellow',
        'gialla' => 'yellow',
        'gialle' => 'yellow',
        'dorato' => 'gold',
        'argento' => 'silver',
        'arancione' => 'orange',
        'viola' => 'purple',

        // German
        'blau' => 'blue',
        'weiss' => 'white',
        'weiß' => 'white',
        'rot' => 'red',
        'gruen' => 'green',
        'grün' => 'green',
        'schwarz' => 'black',
        'gelb' => 'yellow',
        'silber' => 'silver',
    ],

    /*
    | Stripped before matching, so "White Tees", "Men's Blue" and "Championship
    | Gold" all resolve to their color.
    |
    | Keep this list strictly to words that are never colors. "Sand" looks like
    | noise on a golf course but is a real tee color, and lives in `extended`.
    */
    'ignore' => [
        'tee', 'tees', 'teebox', 'teeboxes',
        'men', 'mens', 'man', 'women', 'womens', 'ladies', 'lady', 'gents',
        'senior', 'seniors', 'sr', 'junior', 'juniors', 'jr', 'family', 'youth',
        'championship', 'champ', 'champion', 'tournament', 'tour', 'players',
        'forward', 'back', 'middle', 'front', 'regular', 'standard', 'hybrid',
        'member', 'members', 'pro', 'tips', 'course', 'combo', 'combination',
        'executive', 'short', 'long', 'the', 'and',
        'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x',
    ],
];
