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
        // The e_commerce catalog is priced in US dollars; the quotation and
        // invoice sample data says "Dollars" too. Change these four values
        // together if the schema ever moves back to another currency.
        'currency'         => '$',
        'currency_name'    => 'Dollars',
        'currency_subunit' => 'Cents',
    ],
];
