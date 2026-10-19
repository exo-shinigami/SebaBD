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
     * system_branch_id() in helpers.php, and database/e_commerce.sql which
     * seeds them).
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
     * LEGACY guard for individual customer quotations. Individual accounts can
     * no longer request quotations at all (can_request_quotation() in
     * helpers.php, and refuse_quotation_request() on the form), so the form
     * never files one. The setting is kept so an existing configuration keeps
     * loading, and it still governs the unreachable individual branch of the
     * quotation handler. Leave it false: a SebaBD house quotation is the
     * company's own document, not a customer document.
     */
    'allow_house_quotation_to_individual' => false,

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
    'allow_individual_quotations' => false,
];