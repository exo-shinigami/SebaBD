<?php
/**
 * SebaBD — procurement & vendors report.
 *
 * What SebaBD buys, from whom and at what cost: every purchase order with its
 * line items, rolled up per vendor, plus the margin each bought product would
 * make at its current standard selling price.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\processes\CalculatedColumn;

class ProcurementReport extends SebaBdReport
{
    protected function setup()
    {
        /* Spend totals ------------------------------------------------------- */
        $this->src('db')->query(
            "SELECT COUNT(*)                             AS PurchaseOrders,
                    COALESCE(SUM(po.TotalAmount), 0)     AS Purchased,
                    COALESCE(AVG(po.TotalAmount), 0)     AS AvgOrder,
                    COUNT(DISTINCT po.VendorID)          AS Vendors,
                    DATE_FORMAT(MIN(po.PODate), '%d %b %Y') AS FirstOrder,
                    DATE_FORMAT(MAX(po.PODate), '%d %b %Y') AS LastOrder
             FROM purchase_order po"
        )->pipe($this->dataStore('totals'));

        /* Spend per vendor --------------------------------------------------- */
        $this->src('db')->query(
            "SELECT v.VendorName          AS Vendor,
                    v.Location           AS Location,
                    COUNT(po.PONo)       AS PurchaseOrders,
                    SUM(po.TotalAmount)  AS Purchased,
                    ROUND(100 * SUM(po.TotalAmount) / NULLIF((SELECT SUM(TotalAmount) FROM purchase_order), 0), 1) AS SharePct
             FROM purchase_order po
             JOIN vendor v ON v.VendorID = po.VendorID
             GROUP BY v.VendorID, v.VendorName, v.Location
             ORDER BY Purchased DESC"
        )->pipe($this->dataStore('by_vendor'));

        /* Spend per month ---------------------------------------------------- */
        $this->src('db')->query(
            "SELECT DATE_FORMAT(po.PODate, '%b %Y') AS Month,
                    COUNT(*)                        AS PurchaseOrders,
                    SUM(po.TotalAmount)             AS Purchased
             FROM purchase_order po
             GROUP BY DATE_FORMAT(po.PODate, '%Y-%m'), DATE_FORMAT(po.PODate, '%b %Y')
             ORDER BY DATE_FORMAT(po.PODate, '%Y-%m')"
        )->pipe($this->dataStore('by_month'));

        /* The purchase orders themselves ------------------------------------- */
        $this->src('db')->query(
            "SELECT po.PONo                                          AS PONo,
                    DATE_FORMAT(po.PODate, '%d %b %Y')               AS PONDate,
                    v.VendorName                                     AS Vendor,
                    v.Location                                       AS Location,
                    (SELECT COUNT(*) FROM po_item x WHERE x.PONo = po.PONo) AS Items,
                    po.Terms                                         AS Terms,
                    po.TotalAmount                                   AS Total
             FROM purchase_order po
             JOIN vendor v ON v.VendorID = po.VendorID
             ORDER BY po.PODate DESC"
        )->pipe($this->dataStore('purchase_orders'));

        /* What has been bought, and what it could sell for -------------------- */
        $this->src('db')->query(
            "SELECT p.ProductName                    AS Product,
                    c.CategoryName                   AS Category,
                    v.VendorName                     AS Vendor,
                    SUM(pi.Quantity)                 AS Units,
                    ROUND(AVG(pi.WholesaleUnitPrice), 2) AS AvgCost,
                    p.StandardPrice                  AS Retail,
                    SUM(pi.TotalPrice)               AS Purchased
             FROM po_item pi
             JOIN product  p ON p.ProductID = pi.ProductID
             JOIN category c ON c.CategoryID = p.CategoryID
             JOIN vendor   v ON v.VendorID = p.VendorID
             GROUP BY p.ProductID, p.ProductName, c.CategoryName, v.VendorName, p.StandardPrice
             ORDER BY Purchased DESC"
        )
            ->pipe(new CalculatedColumn([
                'PotentialRevenue' => ['exp' => '{Units} * {Retail}', 'type' => 'number'],
                'MarginPct'        => [
                    // PHP expression: {column} placeholders are substituted with values.
                    'exp'  => '{Retail} > 0 ? round(100 * ({Retail} - {AvgCost}) / {Retail}, 1) : 0',
                    'type' => 'number',
                ],
            ]))
            ->pipe($this->dataStore('purchased_products'));
    }
}
