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
     * The two house accounts (client rows with AccountType = 'house'). They
     * are matched by name, so if your database already calls them something
     * else, change the names here rather than in the code — and if a row is
     * missing, the application creates the walk-in rows on first use (see
     * system_branch_id() in helpers.php, and
     * database/migrate-internal-workflow.sql which seeds them).
     *
     *   walkin    a visitor without a company record — quotations with no
     *             branch, filed at the sales counter
     *   internal  LEGACY — staff do not order from the storefront any more,
     *             so nothing files here now; the row stays because historical
     *             Internal orders and quotations point at it
     *
     * Customer contact accounts (the individual logins created through
     * register.php and the company logins created through b2b-registration.php)
     * are NOT house accounts. They each own their own `client` row (with
     * AccountType 'individual' or 'b2b') and their own quotation/Invoice
     * records via quotation.ClientID / invoice.ClientID. Individuals have no
     * branch, so an individual's quotation is filed with BranchID NULL — they
     * are not quoted under Walk-in Customers, and individuals are not permitted
     * to request a quotation addressed to the SebaBD House account.
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

    /**
     * Email verification is off by default. The site does not send the user
     * anything. Turn it on only if the site is actually configured to send
     * mail (SMTP, sendmail, or whatever the platform provides) and the
     * verification handler in register.php has been wired up.
     */
    'email_verification_enabled' => false,

    /**
     * Individual customers (register.php) are not allowed to file a quotation
     * addressed to the SebaBD House account. Their request is always prepared
     * for their own account (quotation.ClientID = their own client row,
     * BranchID = NULL). The setting is on by default and should stay on:
     * a SebaBD house quotation is the company's own document, not a customer
     * document.
     */
    'allow_house_quotation_to_individual' => false,
];