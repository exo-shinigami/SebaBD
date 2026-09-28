<?php
/**
 * SebaBD — stock & warranty report.
 *
 * Two kinds of stock live in this schema and the report treats them uniformly:
 *
 *   product.StockQty            boxed units for products that are not tracked
 *                               individually (IsSerialized = 0)
 *   product_instance.Status     one row per serialized unit; the ones marked
 *                               'In Stock' are what is actually on the shelf
 *
 * Warranty dates live on the instance as well, so cover can be reported per
 * serial number.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\processes\CalculatedColumn;

class StockReport extends SebaBdReport
{
    /**
     * Expression that resolves the units on hand for a product row, whichever
     * way that product tracks its stock. Used by the queries below.
     *
     * Wrapped in parentheses so it can be summed, multiplied and subtracted
     * without the surrounding operators binding to the sub-query alone.
     */
    private const ON_HAND = '(p.StockQty + (SELECT COUNT(*) FROM product_instance pi
                                            WHERE pi.ProductID = p.ProductID AND pi.Status = \'In Stock\'))';

    protected function setup()
    {
        /* Product level stock, with the value at the standard price ---------- */
        $this->src('db')->query(
            "SELECT p.ProductID                             AS ProductID,
                    p.ProductName                           AS Product,
                    p.Brand                                 AS Brand,
                    c.CategoryName                          AS Category,
                    v.VendorName                            AS Vendor,
                    p.IsSerialized                          AS Serialised,
                    p.DefaultWarrantyMonths                 AS WarrantyMonths,
                    p.StockQty                              AS BoxedQty,
                    (SELECT COUNT(*) FROM product_instance pi
                      WHERE pi.ProductID = p.ProductID AND pi.Status = 'In Stock') AS SerialisedQty,
                    " . self::ON_HAND . "                   AS OnHand,
                    p.StandardPrice                         AS Retail
             FROM product p
             JOIN category c ON c.CategoryID = p.CategoryID
             JOIN vendor   v ON v.VendorID = p.VendorID
             ORDER BY OnHand DESC"
        )
            ->pipe(new CalculatedColumn([
                'StockValue' => ['exp' => '{OnHand} * {Retail}', 'type' => 'number'],
                'Reorder'    => ['exp' => '{OnHand} <= 5 ? (6 - {OnHand}) : 0', 'type' => 'number'],
            ]))
            ->pipe($this->dataStore('products'));

        /* Stock value per category ------------------------------------------- */
        $this->src('db')->query(
            "SELECT c.CategoryName                      AS Category,
                    COUNT(*)                            AS Products,
                    SUM(" . self::ON_HAND . ")          AS UnitsOnHand,
                    SUM(" . self::ON_HAND . " * p.StandardPrice) AS StockValue
             FROM product p
             JOIN category c ON c.CategoryID = p.CategoryID
             GROUP BY c.CategoryID, c.CategoryName
             ORDER BY StockValue DESC"
        )->pipe($this->dataStore('by_category'));

        /* Restock watchlist -------------------------------------------------- */
        $this->src('db')->query(
            "SELECT p.ProductName                           AS Product,
                    v.VendorName                            AS Vendor,
                    v.ContactPhone                          AS Phone,
                    " . self::ON_HAND . "                   AS OnHand,
                    p.StandardPrice                         AS Retail,
                    GREATEST(6 - " . self::ON_HAND . ", 1)  AS SuggestedQty
             FROM product p
             JOIN vendor v ON v.VendorID = p.VendorID
             HAVING OnHand <= 5
             ORDER BY OnHand"
        )->pipe($this->dataStore('restock'));

        /* Serialized equipment register -------------------------------------- */
        $this->src('db')->query(
            "SELECT pi.EquipmentID                                    AS EquipmentID,
                    pi.SerialNumber                                   AS SerialNumber,
                    p.ProductName                                     AS Product,
                    pi.Status                                         AS Status,
                    DATE_FORMAT(pi.PurchaseDate, '%d %b %Y')          AS PurchasedOn,
                    DATE_FORMAT(pi.VendorWarrantyExpiry, '%d %b %Y')  AS VendorCover,
                    DATE_FORMAT(pi.ClientWarrantyExpiry, '%d %b %Y')  AS ClientCover,
                    DATEDIFF(pi.ClientWarrantyExpiry, CURDATE())      AS DaysLeft,
                    CASE
                        WHEN pi.ClientWarrantyExpiry IS NULL THEN 'Unknown'
                        WHEN pi.ClientWarrantyExpiry < CURDATE() THEN 'Expired'
                        ELSE 'Covered'
                    END                                               AS CoverState
             FROM product_instance pi
             JOIN product p ON p.ProductID = pi.ProductID
             ORDER BY pi.ClientWarrantyExpiry"
        )->pipe($this->dataStore('instances'));

        /* Warranty cover per product ----------------------------------------- */
        $this->src('db')->query(
            "SELECT p.ProductName                                    AS Product,
                    COUNT(*)                                          AS Units,
                    SUM(pi.Status = 'In Stock')                       AS InStock,
                    SUM(pi.Status = 'Sold')                           AS Sold,
                    SUM(pi.ClientWarrantyExpiry >= CURDATE())         AS Covered,
                    SUM(pi.ClientWarrantyExpiry < CURDATE())          AS Expired,
                    DATE_FORMAT(MIN(pi.ClientWarrantyExpiry), '%d %b %Y') AS EarliestExpiry
             FROM product_instance pi
             JOIN product p ON p.ProductID = pi.ProductID
             GROUP BY p.ProductID, p.ProductName
             ORDER BY Units DESC"
        )->pipe($this->dataStore('warranty'));
    }
}
