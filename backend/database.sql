-- =============================================================================
-- PRIME CLASSY CAKE & COOKIES — Production Database Schema
-- =============================================================================
--
-- File: database.sql
-- Engine target: MySQL 8.0+ (developed & verified against MySQL 8.4.11)
-- Charset/collation: utf8mb4 / utf8mb4_unicode_ci
--
-- WHAT THIS FILE IS
--   A structure-and-seed-only export generated directly from the project's
--   Laravel migrations (backend/database/migrations/*.php) after running
--   `php artisan migrate:fresh --seed` against a real MySQL database and
--   dumping the result with mysqldump. It is NOT hand-written SQL — every
--   table, foreign key, index, unique constraint, and CHECK constraint below
--   is exactly what the Laravel migrations produce, so this file and the
--   migrations cannot drift apart silently.
--
-- WHAT DATA IS INCLUDED (and why it is not "dummy data")
--   - `roles`      : the 7 fixed system roles the app's hierarchy is built on
--                    (super_admin, agen, korsal, sales, konsumen, admin, kurir).
--   - `languages`  : the 2 mandatory base locales (id, en).
--   - `migrations` : Laravel's own migration bookkeeping table, pre-populated
--                    so that connecting the Laravel app to a database created
--                    from this file and running `php artisan migrate` later
--                    does not try to re-create tables that already exist.
--   No other table contains any row. Specifically absent, by design:
--     - no user accounts of any kind (0 rows in `users`), no passwords
--     - no products, product variations, categories, or stock
--     - no orders, order items, payments, or transactions
--     - no payment gateway configuration (Xendit/Tripay/Stripe credentials)
--     - no shipping configuration, CMS content, or settings values
--   All of the above must be created by real administrators through the
--   application once deployed, not seeded here.
--
-- HOW TO IMPORT
--   phpMyAdmin : create an empty database (or let this file's own
--                CREATE DATABASE statement do it), then Import > choose
--                this file > Go.
--   CLI        : mysql -u <user> -p < database.sql
--
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `primeclassy`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `primeclassy`;

-- MySQL dump 10.13  Distrib 8.4.11, for macos26.4 (x86_64)
--
-- Host: localhost    Database: primeclassy
-- ------------------------------------------------------
-- Server version	8.4.11

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `causer_id` bigint unsigned DEFAULT NULL,
  `subject_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `event` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `activity_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `activity_logs_causer_id_index` (`causer_id`),
  KEY `activity_logs_event_index` (`event`),
  KEY `activity_logs_created_at_index` (`created_at`),
  CONSTRAINT `activity_logs_causer_id_foreign` FOREIGN KEY (`causer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `agent_profiles`
--

DROP TABLE IF EXISTS `agent_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `store_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_profiles_user_id_unique` (`user_id`),
  CONSTRAINT `agent_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `agent_profiles`
--

LOCK TABLES `agent_profiles` WRITE;
/*!40000 ALTER TABLE `agent_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `agent_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bank_transfer_verifications`
--

DROP TABLE IF EXISTS `bank_transfer_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transfer_verifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_transaction_id` bigint unsigned NOT NULL,
  `bank_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `proof_image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','verified','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `rejection_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bank_transfer_verifications_payment_transaction_id_unique` (`payment_transaction_id`),
  KEY `bank_transfer_verifications_verified_by_foreign` (`verified_by`),
  CONSTRAINT `bank_transfer_verifications_payment_transaction_id_foreign` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bank_transfer_verifications_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bank_transfer_verifications`
--

LOCK TABLES `bank_transfer_verifications` WRITE;
/*!40000 ALTER TABLE `bank_transfer_verifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `bank_transfer_verifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_articles`
--

DROP TABLE IF EXISTS `cms_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('article','news') COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cover_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author_id` bigint unsigned DEFAULT NULL,
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_articles_slug_unique` (`slug`),
  KEY `cms_articles_author_id_foreign` (`author_id`),
  KEY `cms_articles_type_index` (`type`),
  KEY `cms_articles_status_index` (`status`),
  CONSTRAINT `cms_articles_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_articles`
--

LOCK TABLES `cms_articles` WRITE;
/*!40000 ALTER TABLE `cms_articles` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_articles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_articles_translations`
--

DROP TABLE IF EXISTS `cms_articles_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_articles_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cms_article_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `excerpt` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_articles_translations_cms_article_id_language_id_unique` (`cms_article_id`,`language_id`),
  KEY `cms_articles_translations_language_id_foreign` (`language_id`),
  CONSTRAINT `cms_articles_translations_cms_article_id_foreign` FOREIGN KEY (`cms_article_id`) REFERENCES `cms_articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cms_articles_translations_language_id_foreign` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_articles_translations`
--

LOCK TABLES `cms_articles_translations` WRITE;
/*!40000 ALTER TABLE `cms_articles_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_articles_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_homepage_blocks`
--

DROP TABLE IF EXISTS `cms_homepage_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_homepage_blocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('banner','hero','product_highlight','testimonial','custom_html') COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` json DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_homepage_blocks`
--

LOCK TABLES `cms_homepage_blocks` WRITE;
/*!40000 ALTER TABLE `cms_homepage_blocks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_homepage_blocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_homepage_blocks_translations`
--

DROP TABLE IF EXISTS `cms_homepage_blocks_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_homepage_blocks_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cms_homepage_block_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtitle` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chbt_block_language_unique` (`cms_homepage_block_id`,`language_id`),
  KEY `cms_homepage_blocks_translations_language_id_foreign` (`language_id`),
  CONSTRAINT `cms_homepage_blocks_translations_cms_homepage_block_id_foreign` FOREIGN KEY (`cms_homepage_block_id`) REFERENCES `cms_homepage_blocks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cms_homepage_blocks_translations_language_id_foreign` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_homepage_blocks_translations`
--

LOCK TABLES `cms_homepage_blocks_translations` WRITE;
/*!40000 ALTER TABLE `cms_homepage_blocks_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_homepage_blocks_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_pages`
--

DROP TABLE IF EXISTS `cms_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_pages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_pages_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_pages`
--

LOCK TABLES `cms_pages` WRITE;
/*!40000 ALTER TABLE `cms_pages` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_pages_translations`
--

DROP TABLE IF EXISTS `cms_pages_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_pages_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cms_page_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` longtext COLLATE utf8mb4_unicode_ci,
  `seo_title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cms_pages_translations_cms_page_id_language_id_unique` (`cms_page_id`,`language_id`),
  KEY `cms_pages_translations_language_id_foreign` (`language_id`),
  CONSTRAINT `cms_pages_translations_cms_page_id_foreign` FOREIGN KEY (`cms_page_id`) REFERENCES `cms_pages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cms_pages_translations_language_id_foreign` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_pages_translations`
--

LOCK TABLES `cms_pages_translations` WRITE;
/*!40000 ALTER TABLE `cms_pages_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `cms_pages_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `commissions`
--

DROP TABLE IF EXISTS `commissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `order_item_id` bigint unsigned DEFAULT NULL,
  `beneficiary_user_id` bigint unsigned NOT NULL,
  `beneficiary_role` enum('agent','sales') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` enum('pending','approved','paid','reversed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `earned_at` timestamp NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commissions_order_item_id_foreign` (`order_item_id`),
  KEY `commissions_beneficiary_user_id_status_index` (`beneficiary_user_id`,`status`),
  KEY `commissions_order_id_index` (`order_id`),
  KEY `commissions_earned_at_index` (`earned_at`),
  CONSTRAINT `commissions_beneficiary_user_id_foreign` FOREIGN KEY (`beneficiary_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `commissions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `commissions_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `commissions`
--

LOCK TABLES `commissions` WRITE;
/*!40000 ALTER TABLE `commissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `commissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `couriers`
--

DROP TABLE IF EXISTS `couriers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `couriers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('internal','external') COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `external_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `couriers_user_id_foreign` (`user_id`),
  KEY `couriers_agent_id_index` (`agent_id`),
  KEY `couriers_type_index` (`type`),
  CONSTRAINT `couriers_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `couriers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `couriers`
--

LOCK TABLES `couriers` WRITE;
/*!40000 ALTER TABLE `couriers` DISABLE KEYS */;
/*!40000 ALTER TABLE `couriers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `konsumen_addresses`
--

DROP TABLE IF EXISTS `konsumen_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `konsumen_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `label` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Rumah',
  `recipient_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_line` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `konsumen_addresses_user_id_index` (`user_id`),
  CONSTRAINT `konsumen_addresses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `konsumen_addresses`
--

LOCK TABLES `konsumen_addresses` WRITE;
/*!40000 ALTER TABLE `konsumen_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `konsumen_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `languages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `languages`
--

LOCK TABLES `languages` WRITE;
/*!40000 ALTER TABLE `languages` DISABLE KEYS */;
INSERT INTO `languages` (`id`, `code`, `name`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (1,'id','Bahasa Indonesia',1,1,1,'2026-09-05 02:07:59','2026-09-05 02:07:59');
INSERT INTO `languages` (`id`, `code`, `name`, `is_default`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (2,'en','English',0,1,2,'2026-09-05 02:07:59','2026-09-05 02:07:59');
/*!40000 ALTER TABLE `languages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_09_05_082432_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_09_05_090000_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_09_05_090001_add_hierarchy_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_09_05_090002_create_user_closures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_09_05_090003_create_agent_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_09_05_090004_create_konsumen_addresses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_09_05_090005_create_couriers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_09_05_090006_create_languages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_09_05_090020_create_product_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_09_05_090021_create_products_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_09_05_090022_create_product_attributes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_09_05_090023_create_product_variation_attributes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_09_05_090024_create_product_variation_attribute_options_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_09_05_090025_create_product_variations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_09_05_090026_create_product_variation_compositions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_09_05_090027_create_product_images_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_09_05_090028_create_product_fees_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_09_05_090029_create_product_variation_fees_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_09_05_090030_create_product_stocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_09_05_090031_create_product_variation_stocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_09_05_090032_create_stock_movements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_09_05_090040_create_orders_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_09_05_090041_create_payment_methods_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_09_05_090042_create_payment_gateway_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_09_05_090043_create_payment_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_09_05_090044_create_bank_transfer_verifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_09_05_090045_create_order_additional_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_09_05_090046_create_order_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_09_05_090047_create_order_item_adjustments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_09_05_090048_create_returns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_09_05_090049_create_return_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_09_05_090050_create_shipping_providers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_09_05_090051_create_shipping_configurations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_09_05_090052_create_shipments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_09_05_090053_create_commissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_09_05_090060_create_cms_pages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_09_05_090061_create_cms_articles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_09_05_090062_create_cms_homepage_blocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_09_05_090063_create_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_09_05_090070_create_cms_pages_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_09_05_090071_create_cms_articles_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_09_05_090072_create_cms_homepage_blocks_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_09_05_090073_create_products_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_09_05_090074_create_product_variation_attributes_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_09_05_090075_create_product_variation_attribute_options_translations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_09_05_090080_create_activity_logs_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_additional_payments`
--

DROP TABLE IF EXISTS `order_additional_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_additional_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `payment_transaction_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(12,2) unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','paid','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_additional_payments_requested_by_foreign` (`requested_by`),
  KEY `order_additional_payments_payment_transaction_id_foreign` (`payment_transaction_id`),
  KEY `order_additional_payments_order_id_index` (`order_id`),
  CONSTRAINT `order_additional_payments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_additional_payments_payment_transaction_id_foreign` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_additional_payments_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_additional_payments`
--

LOCK TABLES `order_additional_payments` WRITE;
/*!40000 ALTER TABLE `order_additional_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_additional_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_item_adjustments`
--

DROP TABLE IF EXISTS `order_item_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_item_adjustments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_item_id` bigint unsigned NOT NULL,
  `adjusted_by` bigint unsigned NOT NULL,
  `quantity_reduced` int unsigned NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `refund_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `refund_status` enum('not_required','pending','processed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_item_adjustments_adjusted_by_foreign` (`adjusted_by`),
  KEY `order_item_adjustments_order_item_id_index` (`order_item_id`),
  CONSTRAINT `order_item_adjustments_adjusted_by_foreign` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `order_item_adjustments_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_item_adjustments`
--

LOCK TABLES `order_item_adjustments` WRITE;
/*!40000 ALTER TABLE `order_item_adjustments` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_item_adjustments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `product_variation_id` bigint unsigned DEFAULT NULL,
  `additional_payment_id` bigint unsigned DEFAULT NULL,
  `product_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `variation_label_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_snapshot` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_price_snapshot` decimal(12,2) unsigned NOT NULL,
  `agent_fee_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `sales_fee_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `subtotal_snapshot` decimal(12,2) unsigned NOT NULL,
  `original_quantity` int unsigned NOT NULL,
  `fulfilled_quantity` int unsigned NOT NULL,
  `status` enum('pending','processing','fulfilled','partially_returned','returned','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_additional_payment_id_foreign` (`additional_payment_id`),
  KEY `order_items_order_id_index` (`order_id`),
  KEY `order_items_product_id_index` (`product_id`),
  KEY `order_items_product_variation_id_index` (`product_variation_id`),
  CONSTRAINT `order_items_additional_payment_id_foreign` FOREIGN KEY (`additional_payment_id`) REFERENCES `order_additional_payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `order_items_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `konsumen_id` bigint unsigned NOT NULL,
  `sales_id` bigint unsigned DEFAULT NULL,
  `korsal_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `source_address_id` bigint unsigned DEFAULT NULL,
  `status` enum('diterima','diproses','dikirim','terkirim','dibatalkan','pengembalian','kembali') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'diterima',
  `payment_status` enum('unpaid','pending_verification','paid','partially_refunded','refunded','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `subtotal_amount` decimal(12,2) unsigned NOT NULL,
  `discount_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `shipping_fee_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `admin_fee_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `total_amount` decimal(12,2) unsigned NOT NULL,
  `recipient_name_snapshot` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_phone_snapshot` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_snapshot` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude_snapshot` decimal(10,7) DEFAULT NULL,
  `longitude_snapshot` decimal(10,7) DEFAULT NULL,
  `delivery_date_estimate` date DEFAULT NULL,
  `delivery_date_actual` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancellation_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_no_unique` (`order_no`),
  KEY `orders_source_address_id_foreign` (`source_address_id`),
  KEY `orders_cancelled_by_foreign` (`cancelled_by`),
  KEY `orders_agent_id_created_at_index` (`agent_id`,`created_at`),
  KEY `orders_konsumen_id_index` (`konsumen_id`),
  KEY `orders_sales_id_index` (`sales_id`),
  KEY `orders_korsal_id_index` (`korsal_id`),
  KEY `orders_status_index` (`status`),
  KEY `orders_payment_status_index` (`payment_status`),
  CONSTRAINT `orders_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `orders_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_konsumen_id_foreign` FOREIGN KEY (`konsumen_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `orders_korsal_id_foreign` FOREIGN KEY (`korsal_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_source_address_id_foreign` FOREIGN KEY (`source_address_id`) REFERENCES `konsumen_addresses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_gateway_configs`
--

DROP TABLE IF EXISTS `payment_gateway_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_gateway_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_method_id` bigint unsigned NOT NULL,
  `environment` enum('sandbox','production') COLLATE utf8mb4_unicode_ci NOT NULL,
  `config` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_gateway_configs_payment_method_id_environment_unique` (`payment_method_id`,`environment`),
  CONSTRAINT `payment_gateway_configs_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_gateway_configs`
--

LOCK TABLES `payment_gateway_configs` WRITE;
/*!40000 ALTER TABLE `payment_gateway_configs` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_gateway_configs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_methods`
--

DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('manual','gateway','cod') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_methods_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_methods`
--

LOCK TABLES `payment_methods` WRITE;
/*!40000 ALTER TABLE `payment_methods` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_methods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_transactions`
--

DROP TABLE IF EXISTS `payment_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `payment_method_id` bigint unsigned NOT NULL,
  `type` enum('payment','refund','additional_payment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) unsigned NOT NULL,
  `status` enum('pending','processing','success','failed','expired','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `gateway_reference` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_transactions_payment_method_id_gateway_reference_unique` (`payment_method_id`,`gateway_reference`),
  KEY `payment_transactions_order_id_index` (`order_id`),
  KEY `payment_transactions_status_index` (`status`),
  CONSTRAINT `payment_transactions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `payment_transactions_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_transactions`
--

LOCK TABLES `payment_transactions` WRITE;
/*!40000 ALTER TABLE `payment_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_attributes`
--

DROP TABLE IF EXISTS `product_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_attributes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `attribute_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attribute_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_attributes_product_id_index` (`product_id`),
  CONSTRAINT `product_attributes_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_attributes`
--

LOCK TABLES `product_attributes` WRITE;
/*!40000 ALTER TABLE `product_attributes` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_categories`
--

DROP TABLE IF EXISTS `product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_categories_slug_unique` (`slug`),
  KEY `product_categories_parent_id_index` (`parent_id`),
  CONSTRAINT `product_categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_categories`
--

LOCK TABLES `product_categories` WRITE;
/*!40000 ALTER TABLE `product_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_fees`
--

DROP TABLE IF EXISTS `product_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_fees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `beneficiary_role` enum('agent','sales') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_fees_product_id_beneficiary_role_unique` (`product_id`,`beneficiary_role`),
  CONSTRAINT `product_fees_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_fees`
--

LOCK TABLES `product_fees` WRITE;
/*!40000 ALTER TABLE `product_fees` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_images`
--

DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_images` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `product_variation_id` bigint unsigned DEFAULT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_images_product_id_index` (`product_id`),
  KEY `product_images_product_variation_id_index` (`product_variation_id`),
  CONSTRAINT `product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_images_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_images`
--

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_stocks`
--

DROP TABLE IF EXISTS `product_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `quantity_on_hand` int unsigned NOT NULL DEFAULT '0',
  `quantity_reserved` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_stocks_agent_id_product_id_unique` (`agent_id`,`product_id`),
  KEY `product_stocks_product_id_foreign` (`product_id`),
  CONSTRAINT `product_stocks_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_stocks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_stocks`
--

LOCK TABLES `product_stocks` WRITE;
/*!40000 ALTER TABLE `product_stocks` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_stocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_attribute_options`
--

DROP TABLE IF EXISTS `product_variation_attribute_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_attribute_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_variation_attribute_id` bigint unsigned NOT NULL,
  `value` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pvao_attribute_idx` (`product_variation_attribute_id`),
  CONSTRAINT `pvao_attribute_fk` FOREIGN KEY (`product_variation_attribute_id`) REFERENCES `product_variation_attributes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_attribute_options`
--

LOCK TABLES `product_variation_attribute_options` WRITE;
/*!40000 ALTER TABLE `product_variation_attribute_options` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_attribute_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_attribute_options_translations`
--

DROP TABLE IF EXISTS `product_variation_attribute_options_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_attribute_options_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_variation_attribute_option_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `value` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pvaot_option_language_unique` (`product_variation_attribute_option_id`,`language_id`),
  KEY `pvaot_language_fk` (`language_id`),
  CONSTRAINT `pvaot_language_fk` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pvaot_option_fk` FOREIGN KEY (`product_variation_attribute_option_id`) REFERENCES `product_variation_attribute_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_attribute_options_translations`
--

LOCK TABLES `product_variation_attribute_options_translations` WRITE;
/*!40000 ALTER TABLE `product_variation_attribute_options_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_attribute_options_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_attributes`
--

DROP TABLE IF EXISTS `product_variation_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_attributes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_variation_attributes_product_id_index` (`product_id`),
  CONSTRAINT `product_variation_attributes_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_attributes`
--

LOCK TABLES `product_variation_attributes` WRITE;
/*!40000 ALTER TABLE `product_variation_attributes` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_attributes_translations`
--

DROP TABLE IF EXISTS `product_variation_attributes_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_attributes_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_variation_attribute_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pvat_attribute_language_unique` (`product_variation_attribute_id`,`language_id`),
  KEY `product_variation_attributes_translations_language_id_foreign` (`language_id`),
  CONSTRAINT `product_variation_attributes_translations_language_id_foreign` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pvat_attribute_fk` FOREIGN KEY (`product_variation_attribute_id`) REFERENCES `product_variation_attributes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_attributes_translations`
--

LOCK TABLES `product_variation_attributes_translations` WRITE;
/*!40000 ALTER TABLE `product_variation_attributes_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_attributes_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_compositions`
--

DROP TABLE IF EXISTS `product_variation_compositions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_compositions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_variation_id` bigint unsigned NOT NULL,
  `product_variation_attribute_id` bigint unsigned NOT NULL,
  `product_variation_attribute_option_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pvc_variation_attribute_unique` (`product_variation_id`,`product_variation_attribute_id`),
  KEY `pvc_attribute_fk` (`product_variation_attribute_id`),
  KEY `pvc_option_idx` (`product_variation_attribute_option_id`),
  CONSTRAINT `product_variation_compositions_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pvc_attribute_fk` FOREIGN KEY (`product_variation_attribute_id`) REFERENCES `product_variation_attributes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pvc_option_fk` FOREIGN KEY (`product_variation_attribute_option_id`) REFERENCES `product_variation_attribute_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_compositions`
--

LOCK TABLES `product_variation_compositions` WRITE;
/*!40000 ALTER TABLE `product_variation_compositions` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_compositions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_fees`
--

DROP TABLE IF EXISTS `product_variation_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_fees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_variation_id` bigint unsigned NOT NULL,
  `beneficiary_role` enum('agent','sales') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pvf_variation_role_unique` (`product_variation_id`,`beneficiary_role`),
  CONSTRAINT `product_variation_fees_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_fees`
--

LOCK TABLES `product_variation_fees` WRITE;
/*!40000 ALTER TABLE `product_variation_fees` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_fees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variation_stocks`
--

DROP TABLE IF EXISTS `product_variation_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variation_stocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned NOT NULL,
  `product_variation_id` bigint unsigned NOT NULL,
  `quantity_on_hand` int unsigned NOT NULL DEFAULT '0',
  `quantity_reserved` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variation_stocks_agent_id_product_variation_id_unique` (`agent_id`,`product_variation_id`),
  KEY `product_variation_stocks_product_variation_id_foreign` (`product_variation_id`),
  CONSTRAINT `product_variation_stocks_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_variation_stocks_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variation_stocks`
--

LOCK TABLES `product_variation_stocks` WRITE;
/*!40000 ALTER TABLE `product_variation_stocks` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variation_stocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variations`
--

DROP TABLE IF EXISTS `product_variations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_variations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `sku` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(12,2) unsigned NOT NULL,
  `weight_grams` int unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_variations_sku_unique` (`sku`),
  KEY `product_variations_product_id_index` (`product_id`),
  CONSTRAINT `product_variations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variations`
--

LOCK TABLES `product_variations` WRITE;
/*!40000 ALTER TABLE `product_variations` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_variations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `has_variations` tinyint(1) NOT NULL DEFAULT '0',
  `base_price` decimal(12,2) unsigned DEFAULT NULL,
  `weight_grams` int unsigned DEFAULT NULL,
  `status` enum('draft','active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_slug_unique` (`slug`),
  KEY `products_created_by_foreign` (`created_by`),
  KEY `products_category_id_index` (`category_id`),
  KEY `products_status_index` (`status`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `products_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products_translations`
--

DROP TABLE IF EXISTS `products_translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products_translations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `language_id` bigint unsigned NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `products_translations_product_id_language_id_unique` (`product_id`,`language_id`),
  KEY `products_translations_language_id_foreign` (`language_id`),
  CONSTRAINT `products_translations_language_id_foreign` FOREIGN KEY (`language_id`) REFERENCES `languages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `products_translations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products_translations`
--

LOCK TABLES `products_translations` WRITE;
/*!40000 ALTER TABLE `products_translations` DISABLE KEYS */;
/*!40000 ALTER TABLE `products_translations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `return_items`
--

DROP TABLE IF EXISTS `return_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `return_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint unsigned NOT NULL,
  `order_item_id` bigint unsigned NOT NULL,
  `quantity_returned` int unsigned NOT NULL,
  `refund_amount` decimal(12,2) unsigned NOT NULL,
  `restock` tinyint(1) NOT NULL DEFAULT '1',
  `condition_note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `return_items_return_id_index` (`return_id`),
  KEY `return_items_order_item_id_index` (`order_item_id`),
  CONSTRAINT `return_items_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `return_items_return_id_foreign` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `return_items`
--

LOCK TABLES `return_items` WRITE;
/*!40000 ALTER TABLE `return_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `return_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `returns`
--

DROP TABLE IF EXISTS `returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `returns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `evidence_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('requested','under_review','approved','rejected','processing','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `total_refund_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `returns_requested_by_foreign` (`requested_by`),
  KEY `returns_reviewed_by_foreign` (`reviewed_by`),
  KEY `returns_order_id_index` (`order_id`),
  KEY `returns_status_index` (`status`),
  CONSTRAINT `returns_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `returns_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `returns_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `returns`
--

LOCK TABLES `returns` WRITE;
/*!40000 ALTER TABLE `returns` DISABLE KEYS */;
/*!40000 ALTER TABLE `returns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (1,'super_admin','Super Admin','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (2,'agen','Agen','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (3,'korsal','Korsal','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (4,'sales','Sales','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (5,'konsumen','Konsumen','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (6,'admin','Admin','2026-09-05 02:17:09','2026-09-05 02:17:09');
INSERT INTO `roles` (`id`, `slug`, `name`, `created_at`, `updated_at`) VALUES (7,'kurir','Kurir','2026-09-05 02:17:09','2026-09-05 02:17:09');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext COLLATE utf8mb4_unicode_ci,
  `group` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipments`
--

DROP TABLE IF EXISTS `shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `courier_id` bigint unsigned DEFAULT NULL,
  `shipping_provider_id` bigint unsigned DEFAULT NULL,
  `origin_latitude` decimal(10,7) DEFAULT NULL,
  `origin_longitude` decimal(10,7) DEFAULT NULL,
  `destination_latitude` decimal(10,7) DEFAULT NULL,
  `destination_longitude` decimal(10,7) DEFAULT NULL,
  `distance_km` decimal(8,2) unsigned DEFAULT NULL,
  `shipping_fee_snapshot` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `tracking_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','picked_up','in_transit','delivered','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipments_order_id_unique` (`order_id`),
  KEY `shipments_shipping_provider_id_foreign` (`shipping_provider_id`),
  KEY `shipments_courier_id_index` (`courier_id`),
  KEY `shipments_status_index` (`status`),
  CONSTRAINT `shipments_courier_id_foreign` FOREIGN KEY (`courier_id`) REFERENCES `couriers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `shipments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shipments_shipping_provider_id_foreign` FOREIGN KEY (`shipping_provider_id`) REFERENCES `shipping_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipments`
--

LOCK TABLES `shipments` WRITE;
/*!40000 ALTER TABLE `shipments` DISABLE KEYS */;
/*!40000 ALTER TABLE `shipments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipping_configurations`
--

DROP TABLE IF EXISTS `shipping_configurations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipping_configurations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned DEFAULT NULL,
  `shipping_provider_id` bigint unsigned DEFAULT NULL,
  `price_per_km` decimal(10,2) unsigned NOT NULL,
  `minimum_distance_km` decimal(6,2) unsigned NOT NULL DEFAULT '0.00',
  `minimum_charge` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `free_shipping_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `free_shipping_min_amount` decimal(12,2) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipping_configurations_agent_id_unique` (`agent_id`),
  KEY `shipping_configurations_shipping_provider_id_foreign` (`shipping_provider_id`),
  CONSTRAINT `shipping_configurations_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shipping_configurations_shipping_provider_id_foreign` FOREIGN KEY (`shipping_provider_id`) REFERENCES `shipping_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipping_configurations`
--

LOCK TABLES `shipping_configurations` WRITE;
/*!40000 ALTER TABLE `shipping_configurations` DISABLE KEYS */;
/*!40000 ALTER TABLE `shipping_configurations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipping_providers`
--

DROP TABLE IF EXISTS `shipping_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipping_providers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipping_providers_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipping_providers`
--

LOCK TABLES `shipping_providers` WRITE;
/*!40000 ALTER TABLE `shipping_providers` DISABLE KEYS */;
/*!40000 ALTER TABLE `shipping_providers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_variation_id` bigint unsigned DEFAULT NULL,
  `type` enum('in','out','reserve','release','adjustment','transfer_in','transfer_out','return_restock') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `reference_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `stock_movements_product_id_foreign` (`product_id`),
  KEY `stock_movements_product_variation_id_foreign` (`product_variation_id`),
  KEY `stock_movements_created_by_foreign` (`created_by`),
  KEY `stock_movements_agent_id_product_id_index` (`agent_id`,`product_id`),
  KEY `stock_movements_agent_id_product_variation_id_index` (`agent_id`,`product_variation_id`),
  KEY `stock_movements_reference_type_reference_id_index` (`reference_type`,`reference_id`),
  KEY `stock_movements_created_at_index` (`created_at`),
  CONSTRAINT `stock_movements_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_movements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_movements_product_variation_id_foreign` FOREIGN KEY (`product_variation_id`) REFERENCES `product_variations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_movements_target` CHECK ((((`product_id` is not null) and (`product_variation_id` is null)) or ((`product_id` is null) and (`product_variation_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_closures`
--

DROP TABLE IF EXISTS `user_closures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_closures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ancestor_id` bigint unsigned NOT NULL,
  `descendant_id` bigint unsigned NOT NULL,
  `depth` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_closures_ancestor_id_descendant_id_unique` (`ancestor_id`,`descendant_id`),
  KEY `user_closures_descendant_id_index` (`descendant_id`),
  KEY `user_closures_ancestor_id_depth_index` (`ancestor_id`,`depth`),
  CONSTRAINT `user_closures_ancestor_id_foreign` FOREIGN KEY (`ancestor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_closures_descendant_id_foreign` FOREIGN KEY (`descendant_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_closures`
--

LOCK TABLES `user_closures` WRITE;
/*!40000 ALTER TABLE `user_closures` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_closures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `korsal_id` bigint unsigned DEFAULT NULL,
  `sales_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referral_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_referral_code_unique` (`referral_code`),
  KEY `users_role_id_foreign` (`role_id`),
  KEY `users_parent_id_foreign` (`parent_id`),
  KEY `users_agent_id_foreign` (`agent_id`),
  KEY `users_korsal_id_foreign` (`korsal_id`),
  KEY `users_sales_id_foreign` (`sales_id`),
  KEY `users_status_index` (`status`),
  CONSTRAINT `users_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_korsal_id_foreign` FOREIGN KEY (`korsal_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `users_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-05 16:18:03
