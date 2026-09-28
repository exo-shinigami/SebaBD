<?php
/**
 * SebaBD — Example Configuration Template.
 * Copy this file to `config.php` and adjust values for your local environment.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'e_commerce',
        'user'     => 'root',
        'password' => '',          // Default XAMPP password is empty
        'charset'  => 'utf8mb4',
    ],
    'site' => [
        'name'             => 'SebaBD',
        'tagline'          => 'Total IT System Solution',
        'currency'         => '$',
        'currency_name'    => 'Dollars',
        'currency_subunit' => 'Cents',
    ],
];