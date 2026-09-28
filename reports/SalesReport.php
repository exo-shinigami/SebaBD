<?php
/**
 * SebaBD — Sales & revenue report.
 *
 * Order volume and revenue, optionally narrowed to a date range, broken down by
 * month, product category and order status, plus a best-seller ranking built
 * with KoolReport's own data processes (Group → Sort → Limit) rather than SQL.
 */

if (!defined('SEBABD_REPORTS')) {
    http_response_code(403);
    exit('SebaBD report files are not directly accessible.');
}

use koolreport\processes\Group;
use koolreport\processes\Limit;
use koolreport\processes\Sort;

class SalesReport extends SebaBdReport
{
    /** Applied date filter (Y-m-d) or '' when that end is open. */
    public string $from = '';
    public string $to   = '';

    protected function setup()
    {
        $this->from = (string) ($this->params['from'] ?? '');
        $this->to   = (string) ($this->params['to'] ?? '');

        [$whereSql, $bind] = $this->dateFilter('o.OrderDate');

        /* Order level totals ------------------------------------------------- */
        $this->src('db')->query(
            "SELECT COUNT(*)                    AS Orders,
                    COALESCE(SUM(o.TotalAmount), 0)  AS Revenue,
                    COALESCE(AVG(o.TotalAmount), 0)  AS AvgOrder,
                    COUNT(DISTINCT o.UserID)    AS Buyers,
                    COALESCE(SUM(o.OrderStatus = 'Completed'), 0) AS Completed
             FROM `order` o
             $whereSql",
            $bind
        )->pipe($this->dataStore('order_totals'));

        /* Line level totals -------------------------------------------------- */
        $this->src('db')->query(
            "SELECT COALESCE(SUM(oi.Quantity), 0)   AS Units,
                    COALESCE(SUM(oi.TotalPrice), 0) AS LineRevenue,
                    COUNT(DISTINCT oi.ProductID)    AS Products
             FROM order_item oi
             JOIN `order` o ON o.OrderID = oi.OrderID
             $whereSql",
            $bind
        )->pipe($this->dataStore('line_totals'));

        /* Revenue per month -------------------------------------------------- */
        $this->src('db')->query(
            "SELECT DATE_FORMAT(o.OrderDate, '%b %Y') AS Month,
                    COUNT(*)                          AS Orders,
                    SUM(o.TotalAmount)                AS Revenue
             FROM `order` o
             $whereSql
             GROUP BY DATE_FORMAT(o.OrderDate, '%Y-%m'), DATE_FORMAT(o.OrderDate, '%b %Y')
             ORDER BY DATE_FORMAT(o.OrderDate, '%Y-%m')",
            $bind
        )->pipe($this->dataStore('by_month'));

        /* Revenue per category ----------------------------------------------- */
        $this->src('db')->query(
            "SELECT c.CategoryName AS Category,
                    SUM(oi.Quantity)   AS Units,
                    SUM(oi.TotalPrice) AS Revenue
             FROM order_item oi
             JOIN `order` o  ON o.OrderID = oi.OrderID
             JOIN product  p ON p.ProductID = oi.ProductID
             JOIN category c ON c.CategoryID = p.CategoryID
             $whereSql
             GROUP BY c.CategoryID, c.CategoryName
             ORDER BY Revenue DESC",
            $bind
        )->pipe($this->dataStore('by_category'));

        /* Order status mix, with each status' share of the total revenue ------ */
        [$subWhereSql, $subBind] = $this->dateFilter('o2.OrderDate', 'sub');

        $this->src('db')->query(
            "SELECT o.OrderStatus            AS Status,
                    COUNT(*)                 AS Orders,
                    SUM(o.TotalAmount)       AS Revenue,
                    ROUND(100 * SUM(o.TotalAmount) / NULLIF(
                        (SELECT SUM(o2.TotalAmount) FROM `order` o2 $subWhereSql), 0), 1) AS SharePct
             FROM `order` o
             $whereSql
             GROUP BY o.OrderStatus
             ORDER BY Revenue DESC",
            array_merge($bind, $subBind)
        )->pipe($this->dataStore('by_status'));

        /* Best sellers — grouped and ranked by KoolReport processes ----------- */
        $this->src('db')->query(
            "SELECT p.ProductName  AS Product,
                    p.Brand        AS Brand,
                    c.CategoryName AS Category,
                    oi.Quantity,
                    oi.TotalPrice  AS Revenue
             FROM order_item oi
             JOIN `order` o  ON o.OrderID = oi.OrderID
             JOIN product  p ON p.ProductID = oi.ProductID
             JOIN category c ON c.CategoryID = p.CategoryID
             $whereSql",
            $bind
        )
            ->pipe(new Group([
                'by'  => 'Product',
                'sum' => ['Revenue', 'Quantity'],
            ]))
            ->pipe(new Sort(['Revenue' => 'desc']))
            ->pipe(new Limit([5]))
            ->pipe($this->dataStore('best_sellers'));

        /* Most recent orders -------------------------------------------------- */
        $this->src('db')->query(
            "SELECT o.OrderID                                                    AS OrderID,
                    DATE_FORMAT(o.OrderDate, '%d %b %Y')                         AS Placed,
                    u.FullName                                                   AS Customer,
                    (SELECT COUNT(*) FROM order_item x WHERE x.OrderID = o.OrderID) AS Items,
                    o.OrderStatus                                                AS Status,
                    o.TotalAmount                                                AS Total
             FROM `order` o
             JOIN `user` u ON u.UserID = o.UserID
             $whereSql
             ORDER BY o.OrderDate DESC",
            $bind
        )->pipe($this->dataStore('recent_orders'));
    }

    /**
     * Build the WHERE clause shared by every query in this report.
     *
     * @param string $column SQL column to compare against the range.
     * @param string $prefix Distinguishes the bound parameter names when the
     *                       same filter is used twice in one statement: the
     *                       sub-query below binds ":subFromDate" so it cannot
     *                       clash with the outer ":fromDate".
     *
     * @return array{0: string, 1: array<string, string>} [sql, bound params]
     */
    private function dateFilter(string $column, string $prefix = ''): array
    {
        $where = [];
        $bind  = [];
        $from  = ':' . $prefix . 'fromDate';
        $to    = ':' . $prefix . 'toDate';

        if ($this->from !== '') {
            $where[]   = "$column >= $from";
            $bind[$from] = $this->from . ' 00:00:00';
        }
        if ($this->to !== '') {
            $where[]   = "$column <= $to";
            $bind[$to] = $this->to . ' 23:59:59';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $bind];
    }

    /**
     * Human readable description of the active filter, for the view.
     */
    public function rangeLabel(): string
    {
        if ($this->from === '' && $this->to === '') {
            return 'All orders in the database';
        }
        if ($this->from !== '' && $this->to !== '') {
            return date('d M Y', strtotime($this->from)) . ' → ' . date('d M Y', strtotime($this->to));
        }
        if ($this->from !== '') {
            return 'From ' . date('d M Y', strtotime($this->from)) . ' onwards';
        }

        return 'Up to ' . date('d M Y', strtotime($this->to));
    }
}
