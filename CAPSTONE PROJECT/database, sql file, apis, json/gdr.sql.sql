-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 05, 2026 at 03:02 PM
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
-- Database: `gdr`
--

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `expense_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `category`, `description`, `amount`, `expense_date`, `created_at`) VALUES
(9, 'Salaries & Wages', 'salary', 15879.00, '2026-01-26', '2026-03-26 06:08:23'),
(10, 'Product', 'MPI 3 Gris 400g 50pcs', 4250.00, '2026-03-26', '2026-03-26 08:19:59'),
(11, 'Product', 'MPI 3 Gris 230g 50pcs', 1750.00, '2026-02-26', '2026-03-26 08:21:30'),
(12, 'Transportation', 'visiting branches', 5000.00, '2026-01-26', '2026-03-26 08:22:55'),
(15, 'Equipment', 'pen', 12334.00, '2026-03-26', '2026-03-26 08:59:48'),
(17, 'Product', '', 4351.00, '2026-03-26', '2026-03-26 13:51:29'),
(19, 'Advertising', '', 12341.00, '2018-02-14', '2026-03-29 11:42:14'),
(23, 'Product', 'salary', 15152.00, '2026-03-31', '2026-03-31 08:04:01'),
(24, 'Advertising', '', 4654.00, '2026-03-31', '2026-03-31 08:04:09'),
(25, 'Office Supplies', 'basta', 54232.00, '2026-03-31', '2026-03-31 08:08:17'),
(26, 'Utilities', '', 123.00, '2022-03-09', '2026-03-31 09:30:22'),
(27, 'Product', 'Product added: gasol', 1200.00, '2026-04-05', '2026-04-05 02:08:12'),
(28, 'Product', 'Product added: asayte', 523453.00, '2026-04-05', '2026-04-05 02:25:50'),
(29, 'Product', 'Product added: asayte', 523453.00, '2026-04-05', '2026-04-05 02:26:06'),
(30, 'Product', 'Product added: gasol', 34000.00, '2026-04-05', '2026-04-05 07:24:38');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `cost` decimal(10,2) DEFAULT 0.00,
  `stock` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category`, `price`, `cost`, `stock`, `created_at`, `expiry_date`) VALUES
(5, 'Syntium S000 SM 0W40 1L', 'Passenger\'s Vehicle Lubricants', 773.00, 600.00, 16, '2026-03-26 05:56:35', NULL),
(6, 'Syntium 3000 SM 5W/40 1L', 'Passenger\'s Vehicle Lubricants', 560.00, 450.00, 4, '2026-03-26 05:57:21', NULL),
(7, 'Syntium 1000 SM 15W50 1L', 'Passenger\'s Vehicle Lubricants', 480.00, 400.00, 13, '2026-03-26 06:00:03', NULL),
(8, 'Syntium 800 SM 15W50 4L', 'Passenger\'s Vehicle Lubricants', 1232.00, 1100.00, 1, '2026-03-26 06:20:15', NULL),
(9, 'Syntium 800 SM 15W/50 1L', 'Passenger\'s Vehicle Lubricants', 311.00, 250.00, 16, '2026-03-26 06:21:39', NULL),
(10, 'Mach 5 SM 20W50 4L', 'Passenger\'s Vehicle Lubricants', 908.00, 850.00, 4, '2026-03-26 07:17:44', NULL),
(11, 'Mach 5 SM 20W50 1L', 'Passenger\'s Vehicle Lubricants', 232.00, 200.00, 16, '2026-03-26 07:18:08', NULL),
(12, 'Mach 5 SM 20W50 209L', 'Passenger\'s Vehicle Lubricants', 37240.00, 35000.00, 0, '2026-03-26 07:20:11', NULL),
(13, 'NGV 15W-40 4L', 'Passenger\'s Vehicle Lubricants', 968.00, 900.00, 4, '2026-03-26 07:20:45', NULL),
(14, 'GEO SAE 15W-40 209L', 'Passenger\'s Vehicle Lubricants', 31240.00, 30000.00, 0, '2026-03-26 07:22:14', NULL),
(15, 'URANIA Optimo 10W-40 18L', 'Commercial Vehicle Lubricants', 5630.00, 5000.00, 1, '2026-03-26 07:24:30', NULL),
(16, 'Urania Supremo CJ-4 15W40v 1L', 'Commercial Vehicle Lubricants', 0.00, 0.00, 0, '2026-03-26 07:46:20', NULL),
(17, 'Urania Supremo CJ-4 15W40v 1L', 'Commercial Vehicle Lubricants', 206.00, 200.00, 16, '2026-03-26 07:46:42', NULL),
(18, 'Urania Supremo CJ-4 15W40 5L', 'Commercial Vehicle Lubricants', 1013.00, 1000.00, 3, '2026-03-26 07:47:13', NULL),
(19, 'Urania Supremo CI-4 15W40 1L', 'Commercial Vehicle Lubricants', 187.00, 180.00, 16, '2026-03-26 07:47:37', NULL),
(20, 'Urania Supremo CI-4 15W40 5L', 'Commercial Vehicle Lubricants', 921.00, 900.00, 4, '2026-03-26 07:47:57', NULL),
(21, 'Urania Supremo CI-4 15W40 18L', 'Commercial Vehicle Lubricants', 2516.00, 2500.00, 1, '2026-03-26 07:48:21', NULL),
(22, 'Urania Supremo CI-4 15W40 209L', 'Commercial Vehicle Lubricants', 26992.00, 26900.00, 0, '2026-03-26 07:49:04', NULL),
(23, 'Urania Turbo CF-4 20W50 1L', 'Commercial Vehicle Lubricants', 24628.00, 24000.00, 16, '2026-03-26 07:49:38', NULL),
(24, 'URANIA CF 40 1L', 'Commercial Vehicle Lubricants', 155.00, 150.00, 16, '2026-03-26 07:54:45', NULL),
(25, 'URANIA CF 40 18L', 'Commercial Vehicle Lubricants', 2337.00, 1300.00, 1, '2026-03-26 07:55:12', NULL),
(26, 'URANIA CF 40 209L', 'Commercial Vehicle Lubricants', 24820.00, 24500.00, 1, '2026-03-26 07:55:36', NULL),
(27, 'URANIA CF 30 209L', 'Commercial Vehicle Lubricants', 28224.00, 28000.00, 1, '2026-03-26 07:55:55', NULL),
(28, 'HIDRAULIK EP 68 DRUM 209L', 'Industrial Oils', 24326.00, 24000.00, 0, '2026-03-26 07:58:13', NULL),
(29, 'HIDRAULIK EP 68 PAIL 18L', 'Industrial Oils', 2321.00, 2300.00, 1, '2026-03-26 07:58:43', NULL),
(30, 'GL-4  90 PAIL 18L', 'Automotive Gear Oils', 2913.00, 2900.00, 0, '2026-03-26 07:59:19', NULL),
(31, 'GL-4  140 DRUM 209L', 'Automotive Gear Oils', 35750.00, 35700.00, 0, '2026-03-26 07:59:43', NULL),
(32, 'GL-5 90 1L', 'Automotive Gear Oils', 226.00, 220.00, 16, '2026-03-26 08:00:03', NULL),
(33, 'GL-5 90 4L', 'Automotive Gear Oils', 881.00, 750.00, 4, '2026-03-26 08:00:22', NULL),
(34, 'GL-5 90 PAIL 18L', 'Automotive Gear Oils', 3087.00, 3050.00, 1, '2026-03-26 08:00:58', NULL),
(35, 'GL-5 90 DRUM 209L', 'Automotive Gear Oils', 33780.00, 33700.00, 1, '2026-03-26 08:02:01', NULL),
(36, 'GL-5 140 PAIL 18L', 'Automotive Gear Oils', 3558.00, 3500.00, 1, '2026-03-26 08:02:34', NULL),
(37, 'GL-5 140 4L', 'Automotive Gear Oils', 861.00, 850.00, 4, '2026-03-26 08:02:57', NULL),
(38, 'GL-5 140 209L', 'Automotive Gear Oils', 37230.00, 37200.00, 0, '2026-03-26 08:03:15', NULL),
(39, 'GL-5 85W-140 18L', 'Automotive Gear Oils', 3544.00, 3500.00, 1, '2026-03-26 08:03:34', NULL),
(40, 'BRAKE FLUID DOT3 500ML', 'Special Products & Greases', 146.00, 140.00, 21, '2026-03-26 08:04:33', NULL),
(41, 'LL RADIATOR COLLANT 500ML', 'Special Products & Greases', 135.00, 130.00, 30, '2026-03-26 08:05:02', NULL),
(42, 'LL RADIATOR COLLANT 209L', 'Special Products & Greases', 42000.00, 40000.00, 0, '2026-03-26 08:05:50', NULL),
(43, 'ATF-D3 18L', 'Special Products & Greases', 3964.00, 3900.00, 0, '2026-03-26 08:06:25', NULL),
(44, 'GRIS MPI 18KG', 'Special Products & Greases', 5645.00, 5600.00, 1, '2026-03-26 08:06:57', NULL),
(45, 'MOTOGRIS MPA 18KG', 'Special Products & Greases', 5309.00, 5250.00, 1, '2026-03-26 08:07:19', NULL),
(46, 'GRIS LS 3 15KG', 'Special Products & Greases', 2984.00, 2950.00, 1, '2026-03-26 08:07:47', NULL),
(52, 'asayte', 'lubes', 4532.00, 5432.00, 1, '2026-03-31 03:27:35', '2030-03-31');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `product_id`, `quantity`, `unit_price`, `total`, `sale_date`, `created_at`) VALUES
(13, 7, 20, 1232.00, 24640.00, '2026-03-26', '2026-03-26 06:02:50'),
(14, 5, 20, 773.00, 15460.00, '2026-02-26', '2026-03-26 06:03:12'),
(15, 6, 10, 560.00, 5600.00, '2026-01-26', '2026-03-26 06:05:31'),
(16, 8, 4, 1232.00, 4928.00, '2026-03-26', '2026-03-26 06:57:59'),
(17, 7, 1, 480.00, 480.00, '2026-03-26', '2026-03-26 07:00:50'),
(18, 7, 1, 480.00, 480.00, '2026-03-26', '2026-03-26 07:05:43'),
(19, 7, 1, 480.00, 480.00, '2026-03-26', '2026-03-26 07:06:25'),
(20, 6, 12, 560.00, 6720.00, '2026-01-26', '2026-03-26 07:12:35'),
(21, NULL, 1, 5886.00, 5886.00, '2026-03-26', '2026-03-26 14:16:21'),
(22, 31, 1, 35750.00, 35750.00, '2026-03-26', '2026-03-26 14:17:49'),
(23, 14, 1, 31240.00, 31240.00, '2025-03-29', '2026-03-29 11:58:16'),
(24, 22, 1, 26992.00, 26992.00, '2026-03-29', '2026-03-29 18:09:57'),
(25, 43, 1, 3964.00, 3964.00, '2026-03-29', '2026-03-29 18:10:05'),
(26, 30, 1, 2913.00, 2913.00, '2026-03-29', '2026-03-29 18:10:11'),
(27, 40, 1, 146.00, 146.00, '2026-03-29', '2026-03-29 18:10:15'),
(28, 40, 1, 146.00, 146.00, '2026-03-29', '2026-03-29 18:10:18'),
(29, 42, 1, 42000.00, 42000.00, '2026-03-29', '2026-03-29 18:10:23'),
(30, 38, 1, 37230.00, 37230.00, '2026-03-29', '2026-03-29 18:40:30'),
(31, 40, 1, 146.00, 146.00, '2027-03-31', '2026-03-31 03:11:09'),
(32, 12, 1, 37240.00, 37240.00, '2026-02-21', '2026-03-31 03:26:36'),
(33, 52, 1, 4532.00, 4532.00, '2026-03-31', '2026-03-31 04:29:52'),
(34, 28, 1, 24326.00, 24326.00, '2025-10-13', '2026-03-31 08:24:01'),
(35, 52, 10, 4532.00, 45320.00, '2026-04-05', '2026-04-05 02:40:38'),
(36, NULL, 5, 7566454.00, 37832270.00, '2026-04-05', '2026-04-05 02:40:49'),
(37, 18, 1, 1013.00, 1013.00, '2026-04-05', '2026-04-05 07:25:46');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'admin@profitlens.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '2026-03-25 09:26:34'),
(2, 'admin@email.com', '$2y$10$dYWE1t5MQZBBmoP26EXGV.yc3rBG0my3gJXL6cbZRenU4B5U.BBI.', 'user', '2026-03-25 09:44:07'),
(3, 'cram03namme@gmail.com', '$2y$10$wbKan/pYQZ01N7PNNvrqke/fW781NFafJBsL0cLvjDlvfXEdNNNJe', 'user', '2026-03-29 11:52:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
