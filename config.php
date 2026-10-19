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

    /**
     * Optional flag that can be turned on in config.php to let individual
     * customer accounts request quotations again. When false (the default) the
     * storefront keeps the existing behaviour: quotations remain a B2B + walk-in
     * flow and individual accounts continue to order directly. When true, the
     * quotation gate, the refusal page and every nav/button that keys off
     * can_request_quotation() are updated so a signed-in individual customer can
     * reach quotation-request.php and file a quotation under their own client
     * record (BranchID NULL), without any company or branch being created for them.
     */
    'allow_individual_quotations' => true,
];
