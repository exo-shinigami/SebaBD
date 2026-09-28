<?php
/**
 * SebaBD — invoicing & receivables report.
 *
 * What has been billed, what has actually been collected, and what clients
 * still owe. Invoices are linked to the client through their branch, and
 * payment_receipt rows show the money coming in.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\processes\CalculatedColumn;

class InvoiceReport extends SebaBdReport
{
    protected function setup()
    {
        /* Invoice and receipt totals ---------------------------------------- */
        $this->src('db')->query(
            "SELECT COUNT(*)                                  AS Invoices,
                    COALESCE(SUM(i.TotalAmount), 0)           AS Billed,
                    COALESCE(SUM(i.TotalAmount - i.RemainingBalance), 0) AS Collected,
                    COALESCE(SUM(i.RemainingBalance), 0)      AS Outstanding,
                    COALESCE(SUM(i.PaymentStatus = 'PAID'), 0)    AS Paid,
                    COALESCE(SUM(i.PaymentStatus = 'PARTIAL'), 0) AS Partial,
                    COALESCE(SUM(i.PaymentStatus = 'DUE'), 0)     AS Due
             FROM invoice i"
        )->pipe($this->dataStore('invoice_totals'));

        /* Billing vs outstanding per month ---------------------------------- */
        $this->src('db')->query(
            "SELECT DATE_FORMAT(i.InvoiceDate, '%b %Y') AS Month,
                    COUNT(*)                           AS Invoices,
                    SUM(i.TotalAmount)                 AS Billed,
                    SUM(i.RemainingBalance)            AS Outstanding
             FROM invoice i
             GROUP BY DATE_FORMAT(i.InvoiceDate, '%Y-%m'), DATE_FORMAT(i.InvoiceDate, '%b %Y')
             ORDER BY DATE_FORMAT(i.InvoiceDate, '%Y-%m')"
        )->pipe($this->dataStore('by_month'));

        /* Payment status mix ------------------------------------------------ */
        $this->src('db')->query(
            "SELECT i.PaymentStatus AS Status,
                    COUNT(*)        AS Invoices,
                    SUM(i.TotalAmount) AS Billed,
                    SUM(i.RemainingBalance) AS Outstanding,
                    ROUND(100 * SUM(i.TotalAmount) / NULLIF((SELECT SUM(TotalAmount) FROM invoice), 0), 1) AS SharePct
             FROM invoice i
             GROUP BY i.PaymentStatus
             ORDER BY Billed DESC"
        )->pipe($this->dataStore('by_status'));

        /* Who owes what ----------------------------------------------------- */
        $this->src('db')->query(
            "SELECT cl.CompanyName        AS Client,
                    COUNT(i.InvoiceNo)    AS Invoices,
                    SUM(i.TotalAmount)    AS Billed,
                    SUM(i.RemainingBalance) AS Outstanding
             FROM invoice i
             JOIN client_branch b ON b.BranchID = i.BranchID
             JOIN client cl       ON cl.ClientID = b.ClientID
             GROUP BY cl.ClientID, cl.CompanyName
             ORDER BY Outstanding DESC, Billed DESC"
        )->pipe($this->dataStore('by_client'));

        /* Ageing of the money still owed ------------------------------------ */
        $this->src('db')->query(
            "SELECT cl.CompanyName                                  AS Client,
                    i.InvoiceNo                                     AS InvoiceNo,
                    DATE_FORMAT(i.InvoiceDate, '%d %b %Y')          AS Raised,
                    DATEDIFF(CURDATE(), i.InvoiceDate)              AS AgeDays,
                    CASE
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 30 THEN '0-30 days'
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 60 THEN '31-60 days'
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 90 THEN '61-90 days'
                        ELSE '90+ days'
                    END                                             AS AgeBucket,
                    CASE
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 30 THEN 1
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 60 THEN 2
                        WHEN DATEDIFF(CURDATE(), i.InvoiceDate) <= 90 THEN 3
                        ELSE 4
                    END                                             AS BucketOrder,
                    i.TotalAmount                                   AS Total,
                    i.RemainingBalance                              AS Outstanding
             FROM invoice i
             JOIN client_branch b ON b.BranchID = i.BranchID
             JOIN client cl       ON cl.ClientID = b.ClientID
             WHERE i.RemainingBalance > 0
             ORDER BY BucketOrder, i.InvoiceDate"
        )->pipe($this->dataStore('outstanding'));

        /* Every invoice, newest first, with the amount in words -------------- */
        $this->src('db')->query(
            "SELECT i.InvoiceNo                                    AS InvoiceNo,
                    DATE_FORMAT(i.InvoiceDate, '%d %b %Y')         AS Raised,
                    cl.CompanyName                                 AS Client,
                    i.PaymentStatus                                AS Status,
                    i.TotalAmount                                  AS Total,
                    i.RemainingBalance                             AS Outstanding,
                    i.AmountInWords                                AS AmountInWords
             FROM invoice i
             LEFT JOIN client_branch b ON b.BranchID = i.BranchID
             LEFT JOIN client cl       ON cl.ClientID = b.ClientID
             ORDER BY i.InvoiceDate DESC"
        )->pipe($this->dataStore('invoices'));

        /* Money actually received ------------------------------------------- */
        $this->src('db')->query(
            "SELECT r.ReceiptNo                                    AS ReceiptNo,
                    DATE_FORMAT(r.ReceiptDate, '%d %b %Y')         AS Received,
                    r.InvoiceNo                                    AS InvoiceNo,
                    COALESCE(cl.CompanyName, 'Walk-in customer')   AS Client,
                    r.PaymentMethod                                AS Method,
                    r.AmountReceived                               AS Amount,
                    r.ReceivedBy                                   AS ReceivedBy
             FROM payment_receipt r
             JOIN invoice i            ON i.InvoiceNo = r.InvoiceNo
             LEFT JOIN client_branch b ON b.BranchID = i.BranchID
             LEFT JOIN client cl       ON cl.ClientID = b.ClientID
             ORDER BY r.ReceiptDate DESC"
        )->pipe($this->dataStore('receipts'));

        /* Receipts per payment method --------------------------------------- */
        $this->src('db')->query(
            "SELECT r.PaymentMethod        AS Method,
                    COUNT(*)               AS Receipts,
                    SUM(r.AmountReceived)  AS Received
             FROM payment_receipt r
             GROUP BY r.PaymentMethod
             ORDER BY Received DESC"
        )->pipe($this->dataStore('by_method'));

        /* Collection performance: paid vs billed, per client ---------------- */
        $this->src('db')->query(
            "SELECT cl.CompanyName      AS Client,
                    SUM(i.TotalAmount)  AS Billed,
                    SUM(i.RemainingBalance) AS Outstanding
             FROM invoice i
             JOIN client_branch b ON b.BranchID = i.BranchID
             JOIN client cl       ON cl.ClientID = b.ClientID
             GROUP BY cl.ClientID, cl.CompanyName"
        )
            ->pipe(new CalculatedColumn([
                'Collected' => ['exp' => '{Billed} - {Outstanding}', 'type' => 'number'],
                'CollectedPct' => [
                    // The expression is PHP, not SQL: numbers are substituted in.
                    'exp'  => '{Billed} > 0 ? round(100 * ({Billed} - {Outstanding}) / {Billed}, 1) : 0',
                    'type' => 'number',
                ],
            ]))
            ->pipe($this->dataStore('collection'));
    }
}
