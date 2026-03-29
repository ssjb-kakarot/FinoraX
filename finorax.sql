-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 28, 2026 at 08:43 AM
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
-- Database: `finorax`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_logs`
--

CREATE TABLE `ai_logs` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `impact` varchar(100) DEFAULT NULL,
  `priority` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `financial_profiles` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL,
  `age` int(11) NOT NULL,
  `employment_type` varchar(50) NOT NULL,
  `monthly_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_income` decimal(15,2) NOT NULL DEFAULT 0.00,
  `rent_emi` decimal(15,2) NOT NULL DEFAULT 0.00,
  `utilities` decimal(15,2) NOT NULL DEFAULT 0.00,
  `food` decimal(15,2) NOT NULL DEFAULT 0.00,
  `transport` decimal(15,2) NOT NULL DEFAULT 0.00,
  `lifestyle` decimal(15,2) NOT NULL DEFAULT 0.00,
  `healthcare` decimal(15,2) NOT NULL DEFAULT 0.00,
  `education` decimal(15,2) NOT NULL DEFAULT 0.00,
  `entertainment` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other_expenses` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_expenses` decimal(15,2) NOT NULL DEFAULT 0.00,
  `savings_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `savings` decimal(15,2) NOT NULL DEFAULT 0.00,
  `investments` decimal(15,2) NOT NULL DEFAULT 0.00,
  `debt` decimal(15,2) NOT NULL DEFAULT 0.00,
  `insurance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `goal_name` varchar(100) NOT NULL,
  `target_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `goal_years` int(11) NOT NULL,
  `risk_tolerance` varchar(20) NOT NULL,
  `track_expenses` tinyint(1) NOT NULL DEFAULT 0,
  `invest_regularly` tinyint(1) NOT NULL DEFAULT 0,
  `emergency_fund` tinyint(1) NOT NULL DEFAULT 0,
  `primary_concern` varchar(255) NOT NULL,
  `autopilot` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) NOT NULL,
  `date` datetime DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `type` enum('CREDIT','DEBIT') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `platform` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `user_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `dob` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `user_id`, `name`, `phone_number`, `email`, `password`, `dob`) VALUES
(3, 'FX-707900', 'Ankit Sarkar', '8597959264', 'ankitsarkar120706@gmail.com', '$2y$10$FfgEjzQWxLp3AeAWAiIvhO7Izle7TuzckyWpmh9C/QTmH6k9ZHclm', '2026-03-12'),
(4, 'FX-543253', 'Ankit Sarkar', '8597959264', 'ankitsarkarg706@gmail.com', '$2y$10$jPfmq6dLd4Kknv1pNTL.7erBpLeppp9KS1op4dHCSCYImY91MnS/y', '2026-03-20'),
(5, 'FX-494560', 'Ankit Sarkar', '8597959264', 'ankitsarkar120t@mail.com', '$2y$10$2bU5Dj5XS03ZHjH9mmzKYOthnmc3CD3NkZbHQ/F7PuhVmDIIDSrGO', '2026-10-21');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_logs`
--
ALTER TABLE `ai_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `financial_profiles`
--
ALTER TABLE `financial_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id_idx` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ai_logs`
--
ALTER TABLE `ai_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `financial_profiles`
--
ALTER TABLE `financial_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
