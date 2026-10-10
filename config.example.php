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

    /**
     * The two house accounts every quotation and every internal order is filed
     * under. They are matched by name, so if your database already calls them
     * something else, change the names here rather than in the code — and if a
     * row is missing, the application creates it on first use (see
     * system_branch_id() in helpers.php, and database/migrate-internal-workflow.sql
     * which seeds them).
     *
     *   walkin    a visitor with no company asking for a price
     *   internal  a staff member ordering or requesting for SebaBD itself
     */
    'system_accounts' => [
        'walkin' => [
            'company' => 'Walk-in Customers',
            'branch'  => 'Dhaka Sales Counter',
            'contact' => 'Retail Customer',
            'email'   => 'sales@sebabd.com',
            'phone'   => null,
            'address' => 'Dhaka, Bangladesh',
        ],
        'internal' => [
            'company' => 'SebaBD Internal Operations',
            'branch'  => 'SebaBD Head Office — Internal',
            'contact' => 'SebaBD Operations Desk',
            'email'   => 'operations@sebabd.com',
            'phone'   => '+880 1711 100100',
            'address' => 'House 27, Road 11, Banani, Dhaka 1213, Bangladesh',
        ],
    ],
];