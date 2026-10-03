<?php
/**
 * SebaBD — application configuration.
 * Adjust these values to match your MySQL setup (XAMPP defaults shown).
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'e_commerce',
        'user'     => 'root',
        'password' => '',          // XAMPP default: empty
        'charset'  => 'utf8mb4',
    ],
    'site' => [
        'name'     => 'SebaBD',
        'tagline'  => 'Total IT System Solution',
        // The e_commerce catalog is priced in Bangladeshi taka; the quotation
        // and invoice sample data says "Taka" too. Change these three values
        // together if the catalog ever moves to another currency.
        'currency'         => '৳',
        'currency_name'    => 'Taka',
        'currency_subunit' => 'Poisha',
    ],
];
