-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 25, 2026 at 08:39 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `e_commerce`
--
-- Import note: this file creates the `e_commerce` database if it is missing and
-- then builds the 17 tables inside it. Importing on top of an existing
-- `e_commerce` database fails with "table already exists" — drop it first
-- (DROP DATABASE `e_commerce`;) if you want a clean rebuild.

CREATE DATABASE IF NOT EXISTS `e_commerce`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `e_commerce`;

-- --------------------------------------------------------

--
-- Table structure for table `category`
--

CREATE TABLE `category` (
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `category`
--

INSERT INTO `category` (`CategoryID`, `CategoryName`) VALUES
(1, 'Laptops & Computers'),
(2, 'Monitors & Displays'),
(3, 'Networking Equipment'),
(4, 'Accessories');

-- --------------------------------------------------------

--
-- Table structure for table `client`
--

CREATE TABLE `client` (
  `ClientID` int(11) NOT NULL,
  `UserID` int(11) DEFAULT NULL,
  `CompanyName` varchar(255) NOT NULL,
  `ContactPerson` varchar(255) DEFAULT NULL,
  `Email` varchar(255) DEFAULT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `Address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client`
--

INSERT INTO `client` (`ClientID`, `UserID`, `CompanyName`, `ContactPerson`, `Email`, `Phone`, `Address`) VALUES
(1, 4, 'Meghna Infotech Ltd', 'Farhana Rahman', 'contact@meghnainfotech.com', '+880 1711 100104', 'Plot 12, Road 5, Banani, Dhaka 1213'),
(2, NULL, 'Karnaphuli Logistics Ltd', 'Shariful Islam', 'info@karnaphulilogistics.com', '+880 1811 100105', 'Holding 45, Agrabad C/A, Chattogram 4100'),
(3, NULL, 'Bijoy Technologies Ltd', 'Arif Hossain', 'arif@bijoytech.com', '+880 1911 100106', 'Level 8, Bashundhara City, Panthapath, Dhaka 1215'),
(4, NULL, 'Bright Computer Mart', 'Nazmul Hasan', 'sales@brightcomputermart.com', '+880 1611 100107', 'Shop 212, New Market, Mirpur Road, Dhaka 1205');

-- --------------------------------------------------------

--
-- Table structure for table `client_branch`
--

CREATE TABLE `client_branch` (
  `BranchID` int(11) NOT NULL,
  `ClientID` int(11) NOT NULL,
  `BranchName` varchar(255) NOT NULL,
  `BranchAddress` text DEFAULT NULL,
  `ContactNo` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_branch`
--

INSERT INTO `client_branch` (`BranchID`, `ClientID`, `BranchName`, `BranchAddress`, `ContactNo`) VALUES
(1, 1, 'Meghna Infotech - Head Office', 'Plot 12, Road 5, Banani, Dhaka 1213', '+880 1711 100104'),
(2, 1, 'Meghna Infotech - Uttara Branch', 'House 7, Sector 4, Uttara, Dhaka 1230', '+880 1711 100110'),
(3, 2, 'Karnaphuli Logistics - Main Hub', 'Holding 45, Agrabad C/A, Chattogram 4100', '+880 1811 100105'),
(4, 3, 'Bijoy Technologies - Panthapath Office', 'Level 8, Bashundhara City, Panthapath, Dhaka 1215', '+880 1911 100106');

-- --------------------------------------------------------

--
-- Table structure for table `invoice`
--

CREATE TABLE `invoice` (
  `InvoiceNo` int(11) NOT NULL,
  `BranchID` int(11) DEFAULT NULL,
  `QuotationNo` int(11) DEFAULT NULL,
  `OrderID` int(11) DEFAULT NULL,
  `InvoiceDate` date NOT NULL,
  `PaymentStatus` varchar(50) DEFAULT 'DUE',
  `TotalAmount` decimal(12,2) NOT NULL,
  `RemainingBalance` decimal(12,2) NOT NULL,
  `AmountInWords` text DEFAULT NULL
) ;

--
-- Dumping data for table `invoice`
--

INSERT INTO `invoice` (`InvoiceNo`, `BranchID`, `QuotationNo`, `OrderID`, `InvoiceDate`, `PaymentStatus`, `TotalAmount`, `RemainingBalance`, `AmountInWords`) VALUES
(5001, 1, 1001, NULL, '2024-02-12', 'PARTIAL', 420000.00, 120000.00, 'Four Hundred Twenty Thousand Taka'),
(5002, 3, 1003, NULL, '2024-02-22', 'PAID', 612000.00, 0.00, 'Six Hundred Twelve Thousand Taka'),
(5003, 1, NULL, 1, '2024-03-01', 'DUE', 210000.00, 210000.00, 'Two Hundred Ten Thousand Taka'),
(5004, 4, NULL, 4, '2024-03-12', 'PAID', 288000.00, 0.00, 'Two Hundred Eighty Eight Thousand Taka');

-- --------------------------------------------------------

--
-- Table structure for table `invoice_item`
--

CREATE TABLE `invoice_item` (
  `InvoiceItemID` int(11) NOT NULL,
  `InvoiceNo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `EquipmentID` int(11) DEFAULT NULL,
  `Quantity` int(11) NOT NULL,
  `SoldUnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_item`
--

INSERT INTO `invoice_item` (`InvoiceItemID`, `InvoiceNo`, `ProductID`, `EquipmentID`, `Quantity`, `SoldUnitPrice`, `TotalPrice`) VALUES
(1, 5001, 1, 1, 1, 144000.00, 144000.00),
(2, 5001, 2, NULL, 2, 66000.00, 132000.00),
(3, 5002, 3, 4, 1, 102000.00, 102000.00),
(4, 5003, 1, 2, 1, 144000.00, 144000.00),
(5, 5001, 1, NULL, 1, 144000.00, 144000.00),
(6, 5002, 3, NULL, 5, 102000.00, 510000.00),
(7, 5003, 2, NULL, 1, 66000.00, 66000.00),
(8, 5004, 1, NULL, 2, 144000.00, 288000.00);

-- --------------------------------------------------------

--
-- Table structure for table `order`
--

CREATE TABLE `order` (
  `OrderID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `OrderDate` datetime DEFAULT current_timestamp(),
  `TotalAmount` decimal(12,2) NOT NULL,
  `OrderStatus` varchar(50) DEFAULT 'Pending',
  `ShippingAddress` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order`
--

INSERT INTO `order` (`OrderID`, `UserID`, `OrderDate`, `TotalAmount`, `OrderStatus`, `ShippingAddress`) VALUES
(1, 2, '2024-03-01 10:15:00', 210000.00, 'Completed', 'Plot 12, Road 5, Banani, Dhaka 1213'),
(2, 2, '2024-03-05 14:30:00', 132000.00, 'Processing', 'House 21, Road 3, Dhanmondi, Dhaka 1205'),
(3, 1, '2024-03-10 09:00:00', 24000.00, 'Pending', 'Flat 4B, Green Road, Dhaka 1205'),
(4, 2, '2024-03-12 16:45:00', 288000.00, 'Completed', 'Holding 9, Agrabad C/A, Chattogram 4100');

-- --------------------------------------------------------

--
-- Table structure for table `order_item`
--

CREATE TABLE `order_item` (
  `OrderItemID` int(11) NOT NULL,
  `OrderID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `UnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_item`
--

INSERT INTO `order_item` (`OrderItemID`, `OrderID`, `ProductID`, `Quantity`, `UnitPrice`, `TotalPrice`) VALUES
(1, 1, 1, 1, 144000.00, 144000.00),
(2, 1, 2, 1, 66000.00, 66000.00),
(3, 2, 2, 2, 66000.00, 132000.00),
(4, 3, 4, 2, 12000.00, 24000.00),
(5, 4, 1, 2, 144000.00, 288000.00);

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipt`
--

CREATE TABLE `payment_receipt` (
  `ReceiptNo` int(11) NOT NULL,
  `InvoiceNo` int(11) NOT NULL,
  `ReceiptDate` date NOT NULL,
  `AmountReceived` decimal(12,2) NOT NULL,
  `PaymentMethod` varchar(50) DEFAULT NULL,
  `RemainingDueAfterReceipt` decimal(12,2) NOT NULL,
  `ReceivedBy` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_receipt`
--

INSERT INTO `payment_receipt` (`ReceiptNo`, `InvoiceNo`, `ReceiptDate`, `AmountReceived`, `PaymentMethod`, `RemainingDueAfterReceipt`, `ReceivedBy`) VALUES
(8001, 5001, '2024-02-15', 240000.00, 'Bank Transfer', 180000.00, 'Nusrat Jahan'),
(8002, 5002, '2024-02-25', 612000.00, 'Credit Card', 0.00, 'Nusrat Jahan'),
(8003, 5004, '2024-03-12', 288000.00, 'bKash', 0.00, 'Mahmudul Hasan'),
(8004, 5001, '2024-03-15', 60000.00, 'Cheque', 120000.00, 'Nusrat Jahan');

-- --------------------------------------------------------

--
-- Table structure for table `po_item`
--

CREATE TABLE `po_item` (
  `POItemID` int(11) NOT NULL,
  `PONo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `WholesaleUnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `po_item`
--

INSERT INTO `po_item` (`POItemID`, `PONo`, `ProductID`, `Quantity`, `WholesaleUnitPrice`, `TotalPrice`) VALUES
(1, 9001, 1, 10, 120000.00, 1200000.00),
(2, 9002, 2, 20, 54000.00, 1080000.00),
(3, 9003, 3, 10, 84000.00, 840000.00),
(4, 9004, 4, 50, 9000.00, 450000.00);

-- --------------------------------------------------------

--
-- Table structure for table `product`
--

CREATE TABLE `product` (
  `ProductID` int(11) NOT NULL,
  `CategoryID` int(11) NOT NULL,
  `VendorID` int(11) NOT NULL,
  `ProductName` varchar(255) NOT NULL,
  `Brand` varchar(100) DEFAULT NULL,
  `Model` varchar(100) DEFAULT NULL,
  `StandardPrice` decimal(12,2) NOT NULL,
  `IsSerialized` tinyint(1) NOT NULL DEFAULT 0,
  `StockQty` int(11) DEFAULT 0,
  `DefaultWarrantyMonths` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product`
--

INSERT INTO `product` (`ProductID`, `CategoryID`, `VendorID`, `ProductName`, `Brand`, `Model`, `StandardPrice`, `IsSerialized`, `StockQty`, `DefaultWarrantyMonths`) VALUES
(1, 1, 1, 'ProBook 450 G10', 'HP', '450 G10', 144000.00, 1, 0, 24),
(2, 2, 2, 'UltraSharp 27\" 4K Monitor', 'Dell', 'U2723QE', 66000.00, 0, 45, 12),
(3, 3, 3, 'CBS350-24T 24-Port Managed Switch', 'Cisco', 'CBS350-24T', 102000.00, 1, 0, 36),
(4, 4, 4, 'MX Master 3S Wireless Mouse', 'Logitech', 'MX Master 3S', 12000.00, 0, 120, 12);

-- --------------------------------------------------------

--
-- Table structure for table `product_instance`
--

CREATE TABLE `product_instance` (
  `EquipmentID` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `SerialNumber` varchar(100) NOT NULL,
  `PurchaseDate` date DEFAULT NULL,
  `VendorWarrantyExpiry` date DEFAULT NULL,
  `ClientWarrantyExpiry` date DEFAULT NULL,
  `Status` varchar(50) DEFAULT 'In Stock'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_instance`
--

INSERT INTO `product_instance` (`EquipmentID`, `ProductID`, `SerialNumber`, `PurchaseDate`, `VendorWarrantyExpiry`, `ClientWarrantyExpiry`, `Status`) VALUES
(1, 1, '5CG1427W9K', '2024-01-15', '2026-01-15', '2026-01-15', 'In Stock'),
(2, 1, '5CG1427W9L', '2024-01-15', '2026-01-15', '2026-01-15', 'In Stock'),
(3, 3, 'FOC2341X0AB', '2024-02-01', '2027-02-01', '2027-02-01', 'In Stock'),
(4, 3, 'FOC2341X0AC', '2024-02-01', '2027-02-01', '2027-02-01', 'Sold');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order`
--

CREATE TABLE `purchase_order` (
  `PONo` int(11) NOT NULL,
  `VendorID` int(11) NOT NULL,
  `PODate` date NOT NULL,
  `TotalAmount` decimal(12,2) NOT NULL,
  `Terms` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchase_order`
--

INSERT INTO `purchase_order` (`PONo`, `VendorID`, `PODate`, `TotalAmount`, `Terms`) VALUES
(9001, 1, '2024-01-05', 1200000.00, 'Net 30'),
(9002, 2, '2024-01-10', 1080000.00, 'Net 15'),
(9003, 3, '2024-01-20', 840000.00, 'Net 30'),
(9004, 4, '2024-02-01', 450000.00, 'Immediate');

-- --------------------------------------------------------

--
-- Table structure for table `quotation`
--

CREATE TABLE `quotation` (
  `QuotationNo` int(11) NOT NULL,
  `BranchID` int(11) NOT NULL,
  `QuotationDate` date NOT NULL,
  `Subject` varchar(255) DEFAULT NULL,
  `TotalAmount` decimal(12,2) NOT NULL,
  `AmountInWords` text DEFAULT NULL,
  `Status` varchar(50) DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quotation`
--

INSERT INTO `quotation` (`QuotationNo`, `BranchID`, `QuotationDate`, `Subject`, `TotalAmount`, `AmountInWords`, `Status`) VALUES
(1001, 1, '2024-02-10', 'Office IT Upgrade Proposal', 420000.00, 'Four Hundred Twenty Thousand Taka', 'Accepted'),
(1002, 2, '2024-02-15', 'Branch Expansion Equipment', 144000.00, 'One Hundred Forty Four Thousand Taka', 'Pending'),
(1003, 3, '2024-02-20', 'Network Infrastructure Setup', 612000.00, 'Six Hundred Twelve Thousand Taka', 'Accepted'),
(1004, 4, '2024-02-25', 'Peripherals Order', 60000.00, 'Sixty Thousand Taka', 'Rejected');

-- --------------------------------------------------------

--
-- Table structure for table `quotation_item`
--

CREATE TABLE `quotation_item` (
  `QuotationItemID` int(11) NOT NULL,
  `QuotationNo` int(11) NOT NULL,
  `ProductID` int(11) NOT NULL,
  `Quantity` int(11) NOT NULL,
  `UnitPrice` decimal(12,2) NOT NULL,
  `TotalPrice` decimal(12,2) NOT NULL,
  `WarrantyMonths` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quotation_item`
--

INSERT INTO `quotation_item` (`QuotationItemID`, `QuotationNo`, `ProductID`, `Quantity`, `UnitPrice`, `TotalPrice`, `WarrantyMonths`) VALUES
(1, 1001, 1, 2, 144000.00, 288000.00, 24),
(2, 1001, 2, 2, 66000.00, 132000.00, 12),
(3, 1002, 1, 1, 144000.00, 144000.00, 24),
(4, 1003, 3, 6, 102000.00, 612000.00, 36),
(5, 1004, 4, 5, 12000.00, 60000.00, 12);

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `RoleID` int(11) NOT NULL,
  `RoleName` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'),
(4, 'Client Account'),
(5, 'B2B Client Account'),
(3, 'Inventory Manager'),
(2, 'Sales Manager');

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `UserID` int(11) NOT NULL,
  `RoleID` int(11) NOT NULL,
  `Username` varchar(100) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `FullName` varchar(255) NOT NULL,
  `Phone` varchar(50) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`UserID`, `RoleID`, `Username`, `PasswordHash`, `Email`, `FullName`, `Phone`, `CreatedAt`) VALUES
(1, 1, 'admin', '$2y$10$sg9qJdZ9Z48VVpM45KJIuOVW.LzNERCltRXHbTPsl/wSpFtIBSD16', 'mahmudul.hasan@sebabd.com', 'Mahmudul Hasan', '+880 1711 100101', '2026-09-25 12:37:17'),
(2, 2, 'sales', '$2y$10$ngPNRoDB.3UT5gHmj4XJtuOfSFvrhCYFanBJNle2gqCuC7eN9Y/l.', 'nusrat.jahan@sebabd.com', 'Nusrat Jahan', '+880 1811 100102', '2026-09-25 12:37:17'),
(3, 3, 'inventory', '$2y$10$Ia.o8s6YeAmeFh1yNGSbhe7XNnU2q1ZXOmK.CrjUNcJCA0L6EFrzq', 'tanvir.ahmed@sebabd.com', 'Tanvir Ahmed', '+880 1911 100103', '2026-09-25 12:37:17'),
(4, 5, 'client', '$2y$10$wsOwK/s2pfikbrjdlSz/P.ECSf/9q4VCeBLIOo8O9g.3ttgzKIf0y', 'contact@meghnainfotech.com', 'Farhana Rahman', '+880 1711 100104', '2026-09-25 12:37:17');

-- --------------------------------------------------------

--
-- Table structure for table `vendor`
--

CREATE TABLE `vendor` (
  `VendorID` int(11) NOT NULL,
  `VendorName` varchar(255) NOT NULL,
  `Location` varchar(255) DEFAULT NULL,
  `ContactPhone` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendor`
--

INSERT INTO `vendor` (`VendorID`, `VendorName`, `Location`, `ContactPhone`) VALUES
(1, 'Nexus Digital Distribution Pte Ltd', 'Singapore', '+65 6011 0201'),
(2, 'Yuanhe Electronics Co., Ltd', 'Shenzhen, China', '+86 755 8021 0202'),
(3, 'Gulf Micro Trading LLC', 'Dubai, UAE', '+971 4 201 0203'),
(4, 'Techno Parts Sdn Bhd', 'Kuala Lumpur, Malaysia', '+60 3 2011 0204');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `category`
--
ALTER TABLE `category`
  ADD PRIMARY KEY (`CategoryID`);

--
-- Indexes for table `client`
--
ALTER TABLE `client`
  ADD PRIMARY KEY (`ClientID`),
  ADD UNIQUE KEY `UserID` (`UserID`);

--
-- Indexes for table `client_branch`
--
ALTER TABLE `client_branch`
  ADD PRIMARY KEY (`BranchID`),
  ADD KEY `ClientID` (`ClientID`);

--
-- Indexes for table `invoice`
--
ALTER TABLE `invoice`
  ADD PRIMARY KEY (`InvoiceNo`),
  ADD UNIQUE KEY `QuotationNo` (`QuotationNo`),
  ADD UNIQUE KEY `OrderID` (`OrderID`),
  ADD KEY `BranchID` (`BranchID`);

--
-- Indexes for table `invoice_item`
--
ALTER TABLE `invoice_item`
  ADD PRIMARY KEY (`InvoiceItemID`),
  ADD KEY `InvoiceNo` (`InvoiceNo`),
  ADD KEY `ProductID` (`ProductID`),
  ADD KEY `EquipmentID` (`EquipmentID`);

--
-- Indexes for table `order`
--
ALTER TABLE `order`
  ADD PRIMARY KEY (`OrderID`),
  ADD KEY `UserID` (`UserID`);

--
-- Indexes for table `order_item`
--
ALTER TABLE `order_item`
  ADD PRIMARY KEY (`OrderItemID`),
  ADD KEY `OrderID` (`OrderID`),
  ADD KEY `ProductID` (`ProductID`);

--
-- Indexes for table `payment_receipt`
--
ALTER TABLE `payment_receipt`
  ADD PRIMARY KEY (`ReceiptNo`),
  ADD KEY `InvoiceNo` (`InvoiceNo`);

--
-- Indexes for table `po_item`
--
ALTER TABLE `po_item`
  ADD PRIMARY KEY (`POItemID`),
  ADD KEY `PONo` (`PONo`),
  ADD KEY `ProductID` (`ProductID`);

--
-- Indexes for table `product`
--
ALTER TABLE `product`
  ADD PRIMARY KEY (`ProductID`),
  ADD KEY `CategoryID` (`CategoryID`),
  ADD KEY `VendorID` (`VendorID`);

--
-- Indexes for table `product_instance`
--
ALTER TABLE `product_instance`
  ADD PRIMARY KEY (`EquipmentID`),
  ADD UNIQUE KEY `SerialNumber` (`SerialNumber`),
  ADD KEY `ProductID` (`ProductID`);

--
-- Indexes for table `purchase_order`
--
ALTER TABLE `purchase_order`
  ADD PRIMARY KEY (`PONo`),
  ADD KEY `VendorID` (`VendorID`);

--
-- Indexes for table `quotation`
--
ALTER TABLE `quotation`
  ADD PRIMARY KEY (`QuotationNo`),
  ADD KEY `BranchID` (`BranchID`);

--
-- Indexes for table `quotation_item`
--
ALTER TABLE `quotation_item`
  ADD PRIMARY KEY (`QuotationItemID`),
  ADD KEY `QuotationNo` (`QuotationNo`),
  ADD KEY `ProductID` (`ProductID`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`RoleID`),
  ADD UNIQUE KEY `RoleName` (`RoleName`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD UNIQUE KEY `Email` (`Email`),
  ADD KEY `RoleID` (`RoleID`);

--
-- Indexes for table `vendor`
--
ALTER TABLE `vendor`
  ADD PRIMARY KEY (`VendorID`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `client`
--
ALTER TABLE `client`
  ADD CONSTRAINT `client_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE SET NULL;

--
-- Constraints for table `client_branch`
--
ALTER TABLE `client_branch`
  ADD CONSTRAINT `client_branch_ibfk_1` FOREIGN KEY (`ClientID`) REFERENCES `client` (`ClientID`) ON DELETE CASCADE;

--
-- Constraints for table `invoice`
--
ALTER TABLE `invoice`
  ADD CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`),
  ADD CONSTRAINT `invoice_ibfk_2` FOREIGN KEY (`QuotationNo`) REFERENCES `quotation` (`QuotationNo`),
  ADD CONSTRAINT `invoice_ibfk_3` FOREIGN KEY (`OrderID`) REFERENCES `order` (`OrderID`);

--
-- Constraints for table `invoice_item`
--
ALTER TABLE `invoice_item`
  ADD CONSTRAINT `invoice_item_ibfk_1` FOREIGN KEY (`InvoiceNo`) REFERENCES `invoice` (`InvoiceNo`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoice_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`),
  ADD CONSTRAINT `invoice_item_ibfk_3` FOREIGN KEY (`EquipmentID`) REFERENCES `product_instance` (`EquipmentID`) ON DELETE SET NULL;

--
-- Constraints for table `order`
--
ALTER TABLE `order`
  ADD CONSTRAINT `order_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`);

--
-- Constraints for table `order_item`
--
ALTER TABLE `order_item`
  ADD CONSTRAINT `order_item_ibfk_1` FOREIGN KEY (`OrderID`) REFERENCES `order` (`OrderID`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

--
-- Constraints for table `payment_receipt`
--
ALTER TABLE `payment_receipt`
  ADD CONSTRAINT `payment_receipt_ibfk_1` FOREIGN KEY (`InvoiceNo`) REFERENCES `invoice` (`InvoiceNo`);

--
-- Constraints for table `po_item`
--
ALTER TABLE `po_item`
  ADD CONSTRAINT `po_item_ibfk_1` FOREIGN KEY (`PONo`) REFERENCES `purchase_order` (`PONo`) ON DELETE CASCADE,
  ADD CONSTRAINT `po_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

--
-- Constraints for table `product`
--
ALTER TABLE `product`
  ADD CONSTRAINT `product_ibfk_1` FOREIGN KEY (`CategoryID`) REFERENCES `category` (`CategoryID`),
  ADD CONSTRAINT `product_ibfk_2` FOREIGN KEY (`VendorID`) REFERENCES `vendor` (`VendorID`);

--
-- Constraints for table `product_instance`
--
ALTER TABLE `product_instance`
  ADD CONSTRAINT `product_instance_ibfk_1` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

--
-- Constraints for table `purchase_order`
--
ALTER TABLE `purchase_order`
  ADD CONSTRAINT `purchase_order_ibfk_1` FOREIGN KEY (`VendorID`) REFERENCES `vendor` (`VendorID`);

--
-- Constraints for table `quotation`
--
ALTER TABLE `quotation`
  ADD CONSTRAINT `quotation_ibfk_1` FOREIGN KEY (`BranchID`) REFERENCES `client_branch` (`BranchID`);

--
-- Constraints for table `quotation_item`
--
ALTER TABLE `quotation_item`
  ADD CONSTRAINT `quotation_item_ibfk_1` FOREIGN KEY (`QuotationNo`) REFERENCES `quotation` (`QuotationNo`) ON DELETE CASCADE,
  ADD CONSTRAINT `quotation_item_ibfk_2` FOREIGN KEY (`ProductID`) REFERENCES `product` (`ProductID`);

--
-- Constraints for table `user`
--
ALTER TABLE `user`
  ADD CONSTRAINT `user_ibfk_1` FOREIGN KEY (`RoleID`) REFERENCES `role` (`RoleID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
