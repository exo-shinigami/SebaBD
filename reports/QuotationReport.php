<?php
/**
 * SebaBD — quotation pipeline report.
 *
 * A quotation is the shop window of this schema: it carries its own line items,
 * a stored amount in words and a status. Invoices raised from a quotation keep
 * the link through invoice.QuotationNo, which is how this report measures
 * conversion.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

class QuotationReport extends SebaBdReport
{
    protected function setup()
    {
        /* Pipeline totals ---------------------------------------------------- */
        $this->src('db')->query(
            "SELECT COUNT(*)                              AS Quotations,
                    COALESCE(SUM(q.TotalAmount), 0)       AS Quoted,
                    COALESCE(SUM(q.Status = 'Accepted'), 0) AS Accepted,
                    COALESCE(SUM(q.Status = 'Pending'), 0)  AS Pending,
                    COALESCE(SUM(q.Status = 'Rejected'), 0) AS Rejected,
                    (SELECT COUNT(*) FROM invoice i WHERE i.QuotationNo IS NOT NULL) AS Converted,
                    COALESCE((SELECT SUM(i.TotalAmount) FROM invoice i WHERE i.QuotationNo IS NOT NULL), 0) AS ConvertedValue
             FROM quotation q"
        )->pipe($this->dataStore('totals'));

        /* Status mix ---------------------------------------------------------- */
        $this->src('db')->query(
            "SELECT q.Status          AS Status,
                    COUNT(*)          AS Quotations,
                    SUM(q.TotalAmount) AS Quoted,
                    ROUND(100 * SUM(q.TotalAmount) / NULLIF((SELECT SUM(TotalAmount) FROM quotation), 0), 1) AS SharePct
             FROM quotation q
             GROUP BY q.Status
             ORDER BY Quoted DESC"
        )->pipe($this->dataStore('by_status'));

        /* Quoted vs invoiced per month ---------------------------------------- */
        $this->src('db')->query(
            "SELECT DATE_FORMAT(q.QuotationDate, '%b %Y') AS Month,
                    COUNT(*)                              AS Quotations,
                    SUM(q.TotalAmount)                    AS Quoted,
                    COALESCE(SUM(CASE WHEN i.InvoiceNo IS NOT NULL THEN q.TotalAmount ELSE 0 END), 0) AS Converted
             FROM quotation q
             LEFT JOIN invoice i ON i.QuotationNo = q.QuotationNo
             GROUP BY DATE_FORMAT(q.QuotationDate, '%Y-%m'), DATE_FORMAT(q.QuotationDate, '%b %Y')
             ORDER BY DATE_FORMAT(q.QuotationDate, '%Y-%m')"
        )->pipe($this->dataStore('by_month'));

        /* Who is asking ------------------------------------------------------- */
        $this->src('db')->query(
            "SELECT cl.CompanyName        AS Client,
                    b.BranchName          AS Branch,
                    COUNT(q.QuotationNo)  AS Quotations,
                    SUM(q.TotalAmount)    AS Quoted
             FROM quotation q
             JOIN client_branch b ON b.BranchID = q.BranchID
             JOIN client cl       ON cl.ClientID = b.ClientID
             GROUP BY b.BranchID, b.BranchName, cl.CompanyName
             ORDER BY Quoted DESC"
        )->pipe($this->dataStore('by_branch'));

        /* The quotations themselves, with their follow-up document ------------ */
        $this->src('db')->query(
            "SELECT q.QuotationNo                                     AS QuotationNo,
                    DATE_FORMAT(q.QuotationDate, '%d %b %Y')          AS Raised,
                    q.Subject                                         AS Subject,
                    COALESCE(cl.CompanyName, 'Walk-in customer')       AS Client,
                    q.Status                                          AS Status,
                    q.TotalAmount                                     AS Quoted,
                    q.AmountInWords                                   AS AmountInWords,
                    i.InvoiceNo                                       AS InvoiceNo
             FROM quotation q
             LEFT JOIN client_branch b ON b.BranchID = q.BranchID
             LEFT JOIN client cl       ON cl.ClientID = b.ClientID
             LEFT JOIN invoice i       ON i.QuotationNo = q.QuotationNo
             ORDER BY q.QuotationDate DESC"
        )->pipe($this->dataStore('quotations'));

        /* What gets quoted the most ------------------------------------------- */
        $this->src('db')->query(
            "SELECT p.ProductName        AS Product,
                    c.CategoryName       AS Category,
                    COUNT(DISTINCT qi.QuotationNo) AS Quotations,
                    SUM(qi.Quantity)     AS Units,
                    ROUND(AVG(qi.UnitPrice), 2) AS AvgUnitPrice,
                    MAX(qi.WarrantyMonths) AS Warranty,
                    SUM(qi.TotalPrice)   AS Quoted
             FROM quotation_item qi
             JOIN quotation q ON q.QuotationNo = qi.QuotationNo
             JOIN product   p ON p.ProductID = qi.ProductID
             JOIN category  c ON c.CategoryID = p.CategoryID
             GROUP BY p.ProductID, p.ProductName, c.CategoryName
             ORDER BY Quoted DESC"
        )->pipe($this->dataStore('top_items'));
    }

    /**
     * Share of quotations that turned into an invoice, as a percentage.
     */
    public function conversionRate(): float
    {
        $row = $this->dataStore('totals')->get(0) ?: [];

        return ((int) ($row['Quotations'] ?? 0)) > 0
            ? round(100 * (int) $row['Converted'] / (int) $row['Quotations'], 1)
            : 0.0;
    }
}
