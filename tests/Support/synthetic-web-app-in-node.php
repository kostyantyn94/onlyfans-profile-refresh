<?php

/*
 * Prints synthetic signing chunks (tests/Support/SyntheticWebApp.php) with the
 * rules each was built from, one JSON object per line, for
 * synthetic-web-app-in-node.mjs. Run from the project root:
 *
 *   docker compose exec -T app php tests/Support/synthetic-web-app-in-node.php | node tests/Support/synthetic-web-app-in-node.mjs
 */

require __DIR__.'/../../vendor/autoload.php';

use Tests\Support\SyntheticWebApp;

$rules = [
    'static_param' => 'Syn7heticStaticParam0123456789Ab',
    'prefix' => '13579',
    'suffix' => '2468aceb',
    'checksum_indexes' => [0, 3, 3, 5, 8, 11, 11, 11, 14, 17, 19, 20, 22, 24, 24, 25, 27, 28, 30, 31, 31, 32, 33, 34, 35, 36, 36, 37, 38, 38, 39, 39],
    'checksum_constant' => -1517,
];

foreach ([0, 1, 17] as $rotation) {
    echo json_encode(['rotation' => $rotation, 'rules' => $rules, 'chunk' => (new SyntheticWebApp($rules, $rotation))->chunk()]), "\n";
}
