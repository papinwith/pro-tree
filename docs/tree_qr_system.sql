-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 22, 2026 at 01:23 PM
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
-- Database: `tree_qr_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `role_id`, `created_at`) VALUES
(1, 'admin', '$2y$12$cm9oXThdYnPgXPMAagOkkOflGT1pRmmrTdzhgCxJtJXHZ9zMQM8ba', 1, '2026-08-15 17:10:59'),
(2, 'exec_test', '$2y$12$FkjsfO/FJ1R70acWrLCjiuqsH5hov4s8Ek/ZE6OM6lRENmfOyqCke', 2, '2026-08-22 08:48:26'),
(3, 'tree_admin_test', '$2y$12$FkjsfO/FJ1R70acWrLCjiuqsH5hov4s8Ek/ZE6OM6lRENmfOyqCke', 3, '2026-08-22 08:48:26');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` bigint(20) UNSIGNED NOT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `code` char(3) NOT NULL,
  `name_th` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL,
  `name_zh` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES
('001', 'ไม้ยืนต้น', NULL, NULL, '2026-08-22 14:43:18'),
('002', 'ไม้พุ่ม', NULL, NULL, '2026-08-22 14:43:25'),
('003', 'ไม้ล้มลุก', NULL, NULL, '2026-08-22 14:43:32'),
('004', 'ไม้เลื้อย', NULL, NULL, '2026-08-22 14:43:39'),
('005', 'ไม้คลุมดิน', NULL, NULL, '2026-08-22 14:43:46'),
('006', 'ไม้น้ำ', NULL, NULL, '2026-08-22 14:43:51');

-- --------------------------------------------------------

--
-- Table structure for table `highlights`
--

CREATE TABLE `highlights` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `title_en` varchar(200) DEFAULT NULL,
  `title_zh` varchar(200) DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` varchar(20) DEFAULT NULL,
  `zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_logs`
--

CREATE TABLE `maintenance_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tree_id` bigint(20) UNSIGNED NOT NULL,
  `activity` varchar(100) NOT NULL,
  `performed_at` date NOT NULL,
  `performed_by` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `market_interests`
--

CREATE TABLE `market_interests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `shop_id` bigint(20) UNSIGNED DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `consent_at` datetime DEFAULT NULL,
  `purge_after` date DEFAULT NULL,
  `session_hash` char(64) DEFAULT NULL,
  `lead_status` enum('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `news_posts`
--

CREATE TABLE `news_posts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `title_en` varchar(200) DEFAULT NULL,
  `title_zh` varchar(200) DEFAULT NULL,
  `excerpt` varchar(500) DEFAULT NULL,
  `excerpt_en` varchar(500) DEFAULT NULL,
  `excerpt_zh` varchar(500) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `body_en` text DEFAULT NULL,
  `body_zh` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nursery_stock`
--

CREATE TABLE `nursery_stock` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `size_label` varchar(50) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `price` decimal(10,2) DEFAULT NULL,
  `sale_status` enum('available','reserved','sold_out','not_for_sale') NOT NULL DEFAULT 'not_for_sale',
  `sales_channel` varchar(150) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `observations`
--

CREATE TABLE `observations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tree_id` bigint(20) UNSIGNED NOT NULL,
  `observed_at` date NOT NULL,
  `height_cm` decimal(7,1) DEFAULT NULL,
  `canopy_cm` decimal(7,1) DEFAULT NULL,
  `health` enum('good','fair','poor') NOT NULL DEFAULT 'good',
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(150) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `permission_key` varchar(60) NOT NULL,
  `module` varchar(40) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `permission_key`, `module`, `description`) VALUES
(1, 'tree.view', 'tree', 'ดูรายการ/รายละเอียดต้นไม้ในระบบแอดมิน'),
(2, 'tree.create', 'tree', 'เพิ่มต้นไม้ใหม่'),
(3, 'tree.update', 'tree', 'แก้ไขข้อมูลต้นไม้ที่มีอยู่'),
(4, 'tree.status.manage', 'tree', 'สลับสถานะ Active/Inactive (ใช้แทนการลบถาวร)'),
(5, 'tree.image.manage', 'tree', 'อัปโหลด/เปลี่ยนรูปต้นไม้'),
(6, 'tree.order.manage', 'tree', 'จัดลำดับการแสดงผล (Previous/Next)'),
(7, 'tree.map.manage', 'tree', 'ตั้งค่าแผนที่เฉพาะต้น (ภาพ/ลิงก์)'),
(8, 'tree.relationship.manage', 'tree', 'จัดการความสัมพันธ์ต้นก่อนหน้า/ถัดไป'),
(9, 'tree.location.manage', 'tree', 'ย้ายต้นไม้ไปตำแหน่ง/โซน/แหล่งที่มาใหม่'),
(10, 'category.manage', 'master_data', 'จัดการประเภทพืช'),
(11, 'species.manage', 'master_data', 'จัดการชนิดพันธุ์พืช'),
(12, 'zone.manage', 'master_data', 'จัดการโซน/ตำแหน่ง'),
(13, 'origin.manage', 'master_data', 'จัดการแหล่งที่มาของต้นไม้'),
(14, 'qrcode.manage', 'qr', 'สร้าง/พิมพ์ QR Code'),
(15, 'translation.request', 'translation', 'สั่งให้ AI แปลข้อความ'),
(16, 'translation.review', 'translation', 'ตรวจสอบ/อนุมัติคำแปลของ AI'),
(17, 'admin.manage', 'admin_accounts', 'จัดการบัญชีผู้ดูแลระบบและบทบาท'),
(18, 'gemini.config.manage', 'admin_accounts', 'ตั้งค่า Gemini API key/model'),
(19, 'scan.view', 'visitor_data', 'ดูประวัติ/สถิติการสแกน'),
(20, 'visitor.view', 'visitor_data', 'ดูข้อมูลผู้เข้าชม (Visitor ID, IP, User-Agent)'),
(21, 'interest.view', 'visitor_data', 'ดูรายชื่ออีเมลผู้สนใจ'),
(22, 'interest.manage', 'visitor_data', 'อัปเดตสถานะการติดตามผู้สนใจ (Lead)'),
(23, 'dashboard.view', 'reporting', 'เข้าถึงแดชบอร์ดผู้บริหาร'),
(24, 'stats.tree.view', 'reporting', 'สถิติรายต้น'),
(25, 'stats.category.view', 'reporting', 'สถิติรายประเภทพืช'),
(26, 'stats.zone.view', 'reporting', 'สถิติรายโซน'),
(27, 'trends.view', 'reporting', 'แนวโน้มผู้เข้าชม/การสแกนตามช่วงเวลา'),
(28, 'revenue.view', 'reporting', 'ข้อมูลราคา/ยอดขาย (ถ้ามี)'),
(29, 'reports.export', 'reporting', 'ส่งออกรายงานเป็น CSV'),
(30, 'map.settings.manage', 'system', 'ตั้งค่าแผนที่เริ่มต้นของทั้งระบบ'),
(31, 'settings.manage', 'system', 'ตั้งค่าระดับระบบ (โลโก้ ฯลฯ)');

-- --------------------------------------------------------

--
-- Table structure for table `plant_location_history`
--

CREATE TABLE `plant_location_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tree_id` bigint(20) UNSIGNED NOT NULL,
  `from_zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `from_area_code` char(2) DEFAULT NULL,
  `to_area_code` char(2) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `shop_id` bigint(20) UNSIGNED NOT NULL,
  `species_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(200) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `compare_at_price` decimal(10,2) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `sold_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `tag` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qr_tags`
--

CREATE TABLE `qr_tags` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tag_code` varchar(30) NOT NULL,
  `tree_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('printed','installed','damaged','retired') NOT NULL DEFAULT 'printed',
  `installed_at` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `scan_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_key` varchar(40) NOT NULL,
  `name_th` varchar(100) NOT NULL,
  `name_en` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `role_key`, `name_th`, `name_en`, `description`, `is_system`, `created_at`) VALUES
(1, 'programmer', 'โปรแกรมเมอร์', 'Programmer', 'สิทธิ์สูงสุด เข้าถึงได้ทุกส่วนของระบบ CRUD ทุกอย่าง', 1, '2026-08-22 08:28:57'),
(2, 'executive', 'ผู้บริหาร', 'Executive', 'ดูแดชบอร์ดและรายงานเพื่อการตัดสินใจ อ่านอย่างเดียว แก้ไข/ลบข้อมูลไม่ได้', 1, '2026-08-22 08:28:57'),
(3, 'tree_admin', 'ผู้ดูแลข้อมูลต้นไม้', 'Tree Admin', 'จัดการข้อมูลต้นไม้ ชนิดพันธุ์ โซน แหล่งที่มา และ QR Code ประจำวัน', 1, '2026-08-22 08:28:57');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(1, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(1, 17),
(1, 18),
(1, 19),
(1, 20),
(1, 21),
(1, 22),
(1, 23),
(1, 24),
(1, 25),
(1, 26),
(1, 27),
(1, 28),
(1, 29),
(1, 30),
(1, 31),
(2, 19),
(2, 20),
(2, 21),
(2, 23),
(2, 24),
(2, 25),
(2, 26),
(2, 27),
(2, 28),
(2, 29),
(3, 1),
(3, 2),
(3, 3),
(3, 4),
(3, 5),
(3, 6),
(3, 7),
(3, 8),
(3, 9),
(3, 10),
(3, 11),
(3, 12),
(3, 13),
(3, 14),
(3, 15),
(3, 16),
(3, 19),
(3, 24);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('default_map_image', '/assets/map/default-map.jpg', '2026-08-15 17:10:59'),
('default_map_url', 'https://maps.example.com/park-overview', '2026-08-15 17:10:59');

-- --------------------------------------------------------

--
-- Table structure for table `shops`
--

CREATE TABLE `shops` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `rating` decimal(2,1) DEFAULT NULL,
  `review_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `about` text DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `species`
--

CREATE TABLE `species` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_code` char(3) NOT NULL,
  `subtype_id` bigint(20) UNSIGNED DEFAULT NULL,
  `species_code` char(3) NOT NULL,
  `classification_id` char(10) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `name_en` varchar(150) DEFAULT NULL,
  `name_zh` varchar(150) DEFAULT NULL,
  `name_common` varchar(150) DEFAULT NULL,
  `name_scientific` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `description_zh` text DEFAULT NULL,
  `care_instructions` text DEFAULT NULL,
  `care_instructions_en` text DEFAULT NULL,
  `care_instructions_zh` text DEFAULT NULL,
  `characteristics` text DEFAULT NULL,
  `characteristics_en` text DEFAULT NULL,
  `characteristics_zh` text DEFAULT NULL,
  `properties` text DEFAULT NULL,
  `properties_en` text DEFAULT NULL,
  `properties_zh` text DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `benefits_en` text DEFAULT NULL,
  `benefits_zh` text DEFAULT NULL,
  `cautions` text DEFAULT NULL,
  `cautions_en` text DEFAULT NULL,
  `cautions_zh` text DEFAULT NULL,
  `part_uses` text DEFAULT NULL,
  `part_uses_en` text DEFAULT NULL,
  `part_uses_zh` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subtypes`
--

CREATE TABLE `subtypes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_code` char(3) DEFAULT NULL,
  `name_th` varchar(100) NOT NULL,
  `name_en` varchar(100) DEFAULT NULL,
  `name_zh` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subtypes`
--

INSERT INTO `subtypes` (`id`, `category_code`, `name_th`, `name_en`, `name_zh`, `created_at`) VALUES
(4, NULL, 'ไม้ผล', NULL, NULL, '2026-08-22 17:58:30'),
(5, NULL, 'ไม้ดอก', NULL, NULL, '2026-08-22 17:58:40'),
(6, NULL, 'ไม้ประดับ', NULL, NULL, '2026-08-22 17:58:45'),
(7, NULL, 'ไม้ให้ร่มเงา', NULL, NULL, '2026-08-22 17:58:50'),
(8, NULL, 'ไม้เศรษฐกิจ', NULL, NULL, '2026-08-22 17:58:56'),
(9, NULL, 'ไม้ป่า', NULL, NULL, '2026-08-22 17:59:28'),
(13, NULL, 'ไม้ยืนต้น', NULL, NULL, '2026-08-22 18:12:26'),
(14, NULL, 'ไม้มงคล', NULL, NULL, '2026-08-22 18:13:37'),
(15, NULL, 'ไม้หอม', NULL, NULL, '2026-08-22 18:14:15'),
(16, NULL, 'ไม้สมุนไพร', NULL, NULL, '2026-08-22 18:14:39'),
(17, NULL, 'ปาล์ม', NULL, NULL, '2026-08-22 18:15:03'),
(18, NULL, 'ไผ่', NULL, NULL, '2026-08-22 18:15:08'),
(19, NULL, 'ไทรและไม้ในกลุ่มไทร', NULL, NULL, '2026-08-22 18:15:14'),
(20, NULL, 'สน', NULL, NULL, '2026-08-22 18:15:20'),
(21, NULL, 'ไม้พรรณท้องถิ่น', NULL, NULL, '2026-08-22 18:15:26');

-- --------------------------------------------------------

--
-- Table structure for table `translation_drafts`
--

CREATE TABLE `translation_drafts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `entity_type` varchar(30) NOT NULL,
  `entity_id` bigint(20) UNSIGNED NOT NULL,
  `field_name` varchar(60) NOT NULL,
  `lang` char(2) NOT NULL,
  `source_text` text NOT NULL,
  `draft_text` text NOT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'gemini',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trees`
--

CREATE TABLE `trees` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `slug` varchar(150) DEFAULT NULL,
  `species_id` bigint(20) UNSIGNED NOT NULL,
  `zone_id` bigint(20) UNSIGNED NOT NULL,
  `area_code` char(2) NOT NULL DEFAULT '01',
  `plant_code` char(15) DEFAULT NULL,
  `plant_code_updated_at` datetime DEFAULT NULL,
  `label` varchar(150) DEFAULT NULL,
  `status` enum('healthy','needs_attention','removed') NOT NULL DEFAULT 'healthy',
  `data_status` enum('draft','verified','published') NOT NULL DEFAULT 'published',
  `image_path` varchar(255) DEFAULT NULL,
  `map_image_path` varchar(255) DEFAULT NULL,
  `map_url` varchar(255) DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `location_updated_at` datetime DEFAULT NULL,
  `display_order` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tree_interests`
--

CREATE TABLE `tree_interests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tree_id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `activity_type` enum('interest_click','price_request') NOT NULL DEFAULT 'interest_click',
  `contact_channel` varchar(100) DEFAULT NULL,
  `lead_status` enum('new','contacted','converted','closed') NOT NULL DEFAULT 'new',
  `lead_updated_at` datetime DEFAULT NULL,
  `consent_at` datetime DEFAULT NULL,
  `purge_after` date DEFAULT NULL,
  `session_hash` char(64) DEFAULT NULL,
  `visitor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tree_scans`
--

CREATE TABLE `tree_scans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tree_id` bigint(20) UNSIGNED NOT NULL,
  `visitor_id` bigint(20) UNSIGNED NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `scan_lat` decimal(10,7) DEFAULT NULL,
  `scan_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy_m` decimal(6,2) DEFAULT NULL,
  `gps_available` tinyint(1) NOT NULL DEFAULT 0,
  `registered_zone_id` bigint(20) UNSIGNED DEFAULT NULL,
  `registered_lat` decimal(10,7) DEFAULT NULL,
  `registered_lng` decimal(10,7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `visitor_uuid` char(36) NOT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_ip_address` varchar(45) DEFAULT NULL,
  `last_user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `zones`
--

CREATE TABLE `zones` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `zone_code` varchar(50) NOT NULL,
  `zone_number` char(3) NOT NULL,
  `name` varchar(150) NOT NULL,
  `name_en` varchar(150) DEFAULT NULL,
  `name_zh` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `description_zh` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `zones`
--

INSERT INTO `zones` (`id`, `zone_code`, `zone_number`, `name`, `name_en`, `name_zh`, `description`, `description_en`, `description_zh`, `created_at`, `updated_at`) VALUES
(1, 'Z1', '001', 'โซนสวนหน้า', 'Front Garden Zone', '前花园区', 'พื้นที่จัดแสดงหลักใกล้ทางเข้า', 'Main display area near the entrance.', '入口附近的主要展示区。', '2026-08-15 17:10:59', '2026-08-15 17:10:59'),
(2, 'Z2', '002', 'โซน 2', 'Zone 2', '区域2', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(3, 'Z3', '003', 'โซน 3', 'Zone 3', '区域3', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(4, 'Z4', '004', 'โซน 4', 'Zone 4', '区域4', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(5, 'Z5', '005', 'โซน 5', 'Zone 5', '区域5', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(6, 'Z6', '006', 'โซน 6', 'Zone 6', '区域6', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(7, 'Z7', '007', 'โซน 7', 'Zone 7', '区域7', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(8, 'Z8', '008', 'โซน 8', 'Zone 8', '区域8', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(9, 'Z9', '009', 'โซน 9', 'Zone 9', '区域9', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31'),
(10, 'Z10', '010', 'โซน 10', 'Zone 10', '区域10', NULL, NULL, NULL, '2026-08-15 17:12:31', '2026-08-15 17:12:31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_admins_role` (`role_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`,`created_at`),
  ADD KEY `idx_audit_admin` (`admin_id`,`created_at`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `highlights`
--
ALTER TABLE `highlights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_highlights_zone` (`zone_id`),
  ADD KEY `idx_highlights_date` (`event_date`,`is_active`);

--
-- Indexes for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_maintenance_tree_date` (`tree_id`,`performed_at`);

--
-- Indexes for table `market_interests`
--
ALTER TABLE `market_interests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_market_interests_shop` (`shop_id`),
  ADD KEY `idx_market_interests_product` (`product_id`);

--
-- Indexes for table `news_posts`
--
ALTER TABLE `news_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_news_published` (`published_at`,`is_active`);

--
-- Indexes for table `nursery_stock`
--
ALTER TABLE `nursery_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_species` (`species_id`);

--
-- Indexes for table `observations`
--
ALTER TABLE `observations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_observations_tree_date` (`tree_id`,`observed_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permission_key` (`permission_key`);

--
-- Indexes for table `plant_location_history`
--
ALTER TABLE `plant_location_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_plh_from_zone` (`from_zone_id`),
  ADD KEY `fk_plh_to_zone` (`to_zone_id`),
  ADD KEY `fk_plh_admin` (`changed_by`),
  ADD KEY `idx_plh_tree` (`tree_id`,`changed_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_products_species` (`species_id`),
  ADD KEY `idx_products_shop` (`shop_id`,`is_active`);

--
-- Indexes for table `qr_tags`
--
ALTER TABLE `qr_tags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tag_code` (`tag_code`),
  ADD KEY `fk_qrtags_tree` (`tree_id`),
  ADD KEY `idx_qrtags_status` (`status`,`last_checked_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_key` (`role_key`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_permission` (`permission_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `shops`
--
ALTER TABLE `shops`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shops_zone` (`zone_id`,`is_active`);

--
-- Indexes for table `species`
--
ALTER TABLE `species`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_species_category_species` (`category_code`,`species_code`),
  ADD UNIQUE KEY `classification_id` (`classification_id`),
  ADD KEY `fk_species_subtype` (`subtype_id`);

--
-- Indexes for table `subtypes`
--
ALTER TABLE `subtypes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_subtypes_category` (`category_code`);

--
-- Indexes for table `translation_drafts`
--
ALTER TABLE `translation_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_drafts_entity` (`entity_type`,`entity_id`,`status`);

--
-- Indexes for table `trees`
--
ALTER TABLE `trees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_trees_display_order` (`display_order`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `plant_code` (`plant_code`),
  ADD KEY `idx_trees_species` (`species_id`),
  ADD KEY `idx_trees_zone` (`zone_id`);

--
-- Indexes for table `tree_interests`
--
ALTER TABLE `tree_interests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_interests_visitor` (`visitor_id`),
  ADD KEY `idx_interests_tree` (`tree_id`);

--
-- Indexes for table `tree_scans`
--
ALTER TABLE `tree_scans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_scans_visitor` (`visitor_id`),
  ADD KEY `idx_scans_tree_visitor` (`tree_id`,`visitor_id`),
  ADD KEY `idx_scans_tree_time` (`tree_id`,`scanned_at`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `visitor_uuid` (`visitor_uuid`);

--
-- Indexes for table `zones`
--
ALTER TABLE `zones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `zone_code` (`zone_code`),
  ADD UNIQUE KEY `zone_number` (`zone_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `highlights`
--
ALTER TABLE `highlights`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `market_interests`
--
ALTER TABLE `market_interests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `news_posts`
--
ALTER TABLE `news_posts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `nursery_stock`
--
ALTER TABLE `nursery_stock`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `observations`
--
ALTER TABLE `observations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `plant_location_history`
--
ALTER TABLE `plant_location_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qr_tags`
--
ALTER TABLE `qr_tags`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `shops`
--
ALTER TABLE `shops`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `species`
--
ALTER TABLE `species`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `subtypes`
--
ALTER TABLE `subtypes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `translation_drafts`
--
ALTER TABLE `translation_drafts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `trees`
--
ALTER TABLE `trees`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `tree_interests`
--
ALTER TABLE `tree_interests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tree_scans`
--
ALTER TABLE `tree_scans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `zones`
--
ALTER TABLE `zones`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admins`
--
ALTER TABLE `admins`
  ADD CONSTRAINT `fk_admins_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `highlights`
--
ALTER TABLE `highlights`
  ADD CONSTRAINT `fk_highlights_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_logs`
--
ALTER TABLE `maintenance_logs`
  ADD CONSTRAINT `fk_maintenance_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `market_interests`
--
ALTER TABLE `market_interests`
  ADD CONSTRAINT `fk_market_interests_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_market_interests_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `nursery_stock`
--
ALTER TABLE `nursery_stock`
  ADD CONSTRAINT `fk_stock_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `observations`
--
ALTER TABLE `observations`
  ADD CONSTRAINT `fk_observations_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `plant_location_history`
--
ALTER TABLE `plant_location_history`
  ADD CONSTRAINT `fk_plh_admin` FOREIGN KEY (`changed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_plh_from_zone` FOREIGN KEY (`from_zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_plh_to_zone` FOREIGN KEY (`to_zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_plh_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_products_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `qr_tags`
--
ALTER TABLE `qr_tags`
  ADD CONSTRAINT `fk_qrtags_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shops`
--
ALTER TABLE `shops`
  ADD CONSTRAINT `fk_shops_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `species`
--
ALTER TABLE `species`
  ADD CONSTRAINT `fk_species_category` FOREIGN KEY (`category_code`) REFERENCES `categories` (`code`),
  ADD CONSTRAINT `fk_species_subtype` FOREIGN KEY (`subtype_id`) REFERENCES `subtypes` (`id`);

--
-- Constraints for table `subtypes`
--
ALTER TABLE `subtypes`
  ADD CONSTRAINT `fk_subtypes_category` FOREIGN KEY (`category_code`) REFERENCES `categories` (`code`);

--
-- Constraints for table `trees`
--
ALTER TABLE `trees`
  ADD CONSTRAINT `fk_trees_species` FOREIGN KEY (`species_id`) REFERENCES `species` (`id`),
  ADD CONSTRAINT `fk_trees_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`);

--
-- Constraints for table `tree_interests`
--
ALTER TABLE `tree_interests`
  ADD CONSTRAINT `fk_interests_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_interests_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tree_scans`
--
ALTER TABLE `tree_scans`
  ADD CONSTRAINT `fk_scans_tree` FOREIGN KEY (`tree_id`) REFERENCES `trees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_scans_visitor` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
