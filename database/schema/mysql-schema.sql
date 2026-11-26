/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `automations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `condition` json NOT NULL,
  `actions` json NOT NULL,
  `is_live` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automations_user_id_foreign` (`user_id`),
  KEY `automations_client_id_foreign` (`client_id`),
  CONSTRAINT `automations_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `automations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blacklisted_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blacklisted_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blacklisted_emails_client_id_email_unique` (`client_id`,`email`),
  KEY `blacklisted_emails_email_index` (`email`),
  CONSTRAINT `blacklisted_emails_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `blocks_client_id_name_unique` (`client_id`,`name`),
  UNIQUE KEY `blocks_uuid_unique` (`uuid`),
  KEY `blocks_user_id_foreign` (`user_id`),
  CONSTRAINT `blocks_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `blocks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UTC',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sunset_inactive_days` smallint unsigned DEFAULT NULL,
  `postal_address` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clients_uuid_unique` (`uuid`),
  UNIQUE KEY `clients_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_attributes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_attributes_uuid_unique` (`uuid`),
  UNIQUE KEY `contact_attributes_client_id_name_unique` (`client_id`,`name`),
  KEY `contact_attributes_user_id_index` (`user_id`),
  CONSTRAINT `contact_attributes_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_attributes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `list_id` bigint unsigned NOT NULL,
  `batch_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_count` int unsigned NOT NULL DEFAULT '0',
  `processed_count` int unsigned NOT NULL DEFAULT '0',
  `skipped_count` int unsigned NOT NULL DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_imports_user_id_foreign` (`user_id`),
  KEY `contact_imports_list_id_foreign` (`list_id`),
  KEY `contact_imports_client_id_foreign` (`client_id`),
  CONSTRAINT `contact_imports_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_imports_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_list` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `list_id` bigint unsigned NOT NULL,
  `opted_in_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_list_contact_id_list_id_unique` (`contact_id`,`list_id`),
  KEY `contact_list_list_id_foreign` (`list_id`),
  CONSTRAINT `contact_list_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_list_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_mail_clicks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_mail_clicks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_mail_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `link` text COLLATE utf8mb4_unicode_ci,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_mail_clicks_contact_mail_id_foreign` (`contact_mail_id`),
  KEY `contact_mail_clicks_contact_id_foreign` (`contact_id`),
  CONSTRAINT `contact_mail_clicks_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_mail_clicks_contact_mail_id_foreign` FOREIGN KEY (`contact_mail_id`) REFERENCES `contact_mails` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_mails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_mails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `mailable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mailable_id` bigint unsigned DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_data` json DEFAULT NULL,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `open_ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `open_user_agent` text COLLATE utf8mb4_unicode_ci,
  `open_timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `open_country` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `open_city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `clicked_at` timestamp NULL DEFAULT NULL,
  `bounced_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `complained_at` timestamp NULL DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `message_id` bigint unsigned DEFAULT NULL,
  `reserved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_mails_uuid_unique` (`uuid`),
  UNIQUE KEY `contact_mailable_unique` (`contact_id`,`mailable_type`,`mailable_id`),
  KEY `contact_mails_mailable_type_mailable_id_index` (`mailable_type`,`mailable_id`),
  KEY `contact_mails_message_id_foreign` (`message_id`),
  KEY `contact_mails_client_mailable_index` (`client_id`,`mailable_type`,`mailable_id`),
  KEY `contact_mails_sequence_due_index` (`mailable_type`,`status`,`scheduled_at`),
  KEY `contact_mails_client_sent_at_index` (`client_id`,`sent_at`,`bounced_at`,`complained_at`),
  CONSTRAINT `contact_mails_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_mails_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_mails_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_sequence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_sequence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `sequence_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_sequence_contact_id_sequence_id_unique` (`contact_id`,`sequence_id`),
  KEY `contact_sequence_sequence_id_foreign` (`sequence_id`),
  KEY `contact_sequence_completed_at_index` (`completed_at`),
  CONSTRAINT `contact_sequence_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_sequence_sequence_id_foreign` FOREIGN KEY (`sequence_id`) REFERENCES `sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tag` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `tag_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_tag_contact_id_tag_id_unique` (`contact_id`,`tag_id`),
  KEY `contact_tag_tag_id_foreign` (`tag_id`),
  CONSTRAINT `contact_tag_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tag_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_topic`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_topic` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `topic_id` bigint unsigned NOT NULL,
  `subscribed_at` timestamp NULL DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_topic_contact_id_topic_id_unique` (`contact_id`,`topic_id`),
  KEY `contact_topic_topic_id_unsubscribed_at_index` (`topic_id`,`unsubscribed_at`),
  CONSTRAINT `contact_topic_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_topic_topic_id_foreign` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra_attributes` json DEFAULT NULL,
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `unsubscribed_by_contact` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `soft_bounce_count` smallint unsigned NOT NULL DEFAULT '0',
  `last_engaged_at` timestamp NULL DEFAULT NULL,
  `sunset_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contacts_uuid_unique` (`uuid`),
  UNIQUE KEY `contacts_client_id_email_unique` (`client_id`,`email`),
  KEY `contacts_user_id_index` (`user_id`),
  KEY `contacts_timezone_index` (`timezone`),
  KEY `contacts_country_index` (`country`),
  KEY `contacts_client_sunset_index` (`client_id`,`sunset_at`,`last_engaged_at`,`created_at`),
  CONSTRAINT `contacts_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contacts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_credentials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ses',
  `access_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'us-east-1',
  `tenant_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aws_account_id` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `configuration_set_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_access_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sending_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `max_24_hour_send` bigint unsigned NOT NULL DEFAULT '200',
  `max_send_rate` int unsigned NOT NULL DEFAULT '1',
  `sent_last_24_hours` bigint unsigned NOT NULL DEFAULT '0',
  `quota_fetched_at` timestamp NULL DEFAULT NULL,
  `configuration_set_created_at` timestamp NULL DEFAULT NULL,
  `sns_topic_arn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sns_subscription_arn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sns_subscription_confirmed_at` timestamp NULL DEFAULT NULL,
  `open_tracking_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `click_tracking_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `can_create_template` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete_template` tinyint(1) NOT NULL DEFAULT '0',
  `can_send_bulk_email` tinyint(1) NOT NULL DEFAULT '0',
  `can_send_email` tinyint(1) NOT NULL DEFAULT '0',
  `permissions_checked_at` timestamp NULL DEFAULT NULL,
  `permissions_alert_count` int unsigned NOT NULL DEFAULT '0',
  `permissions_alerted_at` timestamp NULL DEFAULT NULL,
  `custom_tracking_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_tracking_domain_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `enforcement_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_credentials_client_id_type_unique` (`client_id`,`type`),
  KEY `email_credentials_user_id_index` (`user_id`),
  CONSTRAINT `email_credentials_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_credentials_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_identities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_from_subdomain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `configuration_set_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `configuration_set_created_at` datetime DEFAULT NULL,
  `tenant_associated_at` datetime DEFAULT NULL,
  `open_tracking_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `click_tracking_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `custom_tracking_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_tracking_domain_verified_at` datetime DEFAULT NULL,
  `custom_link_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_link_domain_verified_at` datetime DEFAULT NULL,
  `cf_custom_hostname_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `last_status_check_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reply_to_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reply_to_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_identities_uuid_unique` (`uuid`),
  UNIQUE KEY `email_identities_client_id_email_unique` (`client_id`,`email`),
  KEY `email_identities_user_id_index` (`user_id`),
  KEY `email_identities_custom_link_domain_index` (`custom_link_domain`),
  KEY `email_identities_tracking_domain_verified_idx` (`custom_tracking_domain`,`custom_tracking_domain_verified_at`),
  CONSTRAINT `email_identities_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_identity_dns_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_identity_dns_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_identity_id` bigint unsigned NOT NULL,
  `type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` smallint unsigned DEFAULT NULL,
  `purpose` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_identity_dns_records_email_identity_id_foreign` (`email_identity_id`),
  CONSTRAINT `email_identity_dns_records_email_identity_id_foreign` FOREIGN KEY (`email_identity_id`) REFERENCES `email_identities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `provider` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ses',
  `operation` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `request_payload` json DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `sns_payload` json DEFAULT NULL,
  `logged_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_logs_client_id_logged_at_index` (`client_id`,`logged_at`),
  KEY `email_logs_provider_index` (`provider`),
  KEY `email_logs_message_id_index` (`message_id`),
  KEY `email_logs_status_index` (`status`),
  CONSTRAINT `email_logs_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_templates_user_id_foreign` (`user_id`),
  KEY `email_templates_client_id_is_default_index` (`client_id`,`is_default`),
  KEY `email_templates_archived_at_index` (`archived_at`),
  CONSTRAINT `email_templates_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `email_templates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `instance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `instance_settings` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `lists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `opt_in_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lists_uuid_unique` (`uuid`),
  UNIQUE KEY `lists_client_id_name_unique` (`client_id`,`name`),
  KEY `lists_user_id_index` (`user_id`),
  CONSTRAINT `lists_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lists_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `manipulations` json NOT NULL,
  `custom_properties` json NOT NULL,
  `generated_conversions` json NOT NULL,
  `responsive_images` json NOT NULL,
  `order_column` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `messageable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `messageable_id` bigint unsigned NOT NULL,
  `email_identity_id` bigint unsigned DEFAULT NULL,
  `email_template_id` bigint unsigned DEFAULT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_text` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci,
  `template_slot_content` longtext COLLATE utf8mb4_unicode_ci,
  `text_content` longtext COLLATE utf8mb4_unicode_ci,
  `markdown_content` longtext COLLATE utf8mb4_unicode_ci,
  `variant` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_user_id_foreign` (`user_id`),
  KEY `messages_messageable_type_messageable_id_index` (`messageable_type`,`messageable_id`),
  KEY `messages_email_identity_id_foreign` (`email_identity_id`),
  KEY `messages_email_template_id_foreign` (`email_template_id`),
  KEY `messages_client_id_foreign` (`client_id`),
  CONSTRAINT `messages_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_email_identity_id_foreign` FOREIGN KEY (`email_identity_id`) REFERENCES `email_identities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_email_template_id_foreign` FOREIGN KEY (`email_template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `newsletters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `segment_id` bigint unsigned DEFAULT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `total_contacts_reached` int unsigned NOT NULL DEFAULT '0',
  `last_dispatched_contact_id` bigint unsigned DEFAULT NULL,
  `template_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `filters` json DEFAULT NULL,
  `content_variables` json DEFAULT NULL,
  `ab_test_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `ab_template_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ab_test_winner_variant` varchar(1) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `newsletters_uuid_unique` (`uuid`),
  KEY `newsletters_user_id_foreign` (`user_id`),
  KEY `newsletters_segment_id_foreign` (`segment_id`),
  KEY `newsletters_client_id_foreign` (`client_id`),
  CONSTRAINT `newsletters_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `newsletters_segment_id_foreign` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `newsletters_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `projectables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projectables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `projectable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `projectable_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projectables_project_id_projectable_id_projectable_type_unique` (`project_id`,`projectable_id`,`projectable_type`),
  KEY `projectables_projectable_type_projectable_id_index` (`projectable_type`,`projectable_id`),
  CONSTRAINT `projectables_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `projects_client_id_name_unique` (`client_id`,`name`),
  UNIQUE KEY `projects_uuid_unique` (`uuid`),
  KEY `projects_user_id_foreign` (`user_id`),
  CONSTRAINT `projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `projects_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `filters` json NOT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `segments_client_id_name_unique` (`client_id`,`name`),
  CONSTRAINT `segments_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequence_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequence_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sequence_id` bigint unsigned NOT NULL,
  `delay_days` int unsigned NOT NULL DEFAULT '0',
  `delay_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'days',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `filters` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sequence_steps_sequence_id_foreign` (`sequence_id`),
  CONSTRAINT `sequence_steps_sequence_id_foreign` FOREIGN KEY (`sequence_id`) REFERENCES `sequences` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `segment_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `filters` json DEFAULT NULL,
  `content_variables` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sequences_user_id_foreign` (`user_id`),
  KEY `sequences_segment_id_foreign` (`segment_id`),
  KEY `sequences_client_id_foreign` (`client_id`),
  CONSTRAINT `sequences_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sequences_segment_id_foreign` FOREIGN KEY (`segment_id`) REFERENCES `segments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sequences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_uuid_unique` (`uuid`),
  UNIQUE KEY `tags_client_id_name_unique` (`client_id`,`name`),
  KEY `tags_user_id_index` (`user_id`),
  CONSTRAINT `tags_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tags_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `test_mails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `test_mails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mailable_id` bigint unsigned NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `test_mails_uuid_unique` (`uuid`),
  KEY `test_mails_client_id_foreign` (`client_id`),
  KEY `test_mails_user_id_foreign` (`user_id`),
  KEY `test_mails_mailable_type_mailable_id_index` (`mailable_type`,`mailable_id`),
  CONSTRAINT `test_mails_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `test_mails_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `topics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `list_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `topics_list_id_name_unique` (`list_id`,`name`),
  UNIQUE KEY `topics_uuid_unique` (`uuid`),
  KEY `topics_user_id_foreign` (`user_id`),
  KEY `topics_client_id_index` (`client_id`),
  CONSTRAINT `topics_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `topics_list_id_foreign` FOREIGN KEY (`list_id`) REFERENCES `lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `topics_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_storage_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_storage_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `provider` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bucket` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `access_key_id` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_access_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path_prefix` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'byomailer/{user_id}',
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_storage_settings_user_id_unique` (`user_id`),
  KEY `user_storage_settings_client_id_foreign` (`client_id`),
  CONSTRAINT `user_storage_settings_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_storage_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'customer',
  `is_owner` tinyint(1) NOT NULL DEFAULT '0',
  `invite_expires_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `deactivated_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `two_factor_secret` text COLLATE utf8mb4_unicode_ci,
  `two_factor_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  KEY `users_role_index` (`role`),
  KEY `users_client_id_foreign` (`client_id`),
  CONSTRAINT `users_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `visual_automation_contact_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visual_automation_contact_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint unsigned NOT NULL,
  `node_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `wait_until` timestamp NULL DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `va_contact_steps_enrollment_node_unique` (`enrollment_id`,`node_id`),
  KEY `visual_automation_contact_steps_enrollment_id_status_index` (`enrollment_id`,`status`),
  KEY `visual_automation_contact_steps_node_id_index` (`node_id`),
  CONSTRAINT `visual_automation_contact_steps_enrollment_id_foreign` FOREIGN KEY (`enrollment_id`) REFERENCES `visual_automation_enrollments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `visual_automation_enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visual_automation_enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `visual_automation_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `va_enrollments_automation_contact_unique` (`visual_automation_id`,`contact_id`),
  KEY `visual_automation_enrollments_contact_id_foreign` (`contact_id`),
  KEY `visual_automation_enrollments_status_index` (`status`),
  CONSTRAINT `visual_automation_enrollments_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visual_automation_enrollments_visual_automation_id_foreign` FOREIGN KEY (`visual_automation_id`) REFERENCES `visual_automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `visual_automations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `visual_automations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `workflow` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `visual_automations_user_id_foreign` (`user_id`),
  KEY `visual_automations_client_id_foreign` (`client_id`),
  CONSTRAINT `visual_automations_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visual_automations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `webhook_endpoint_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `response_status` smallint unsigned DEFAULT NULL,
  `response_body` text COLLATE utf8mb4_unicode_ci,
  `error` text COLLATE utf8mb4_unicode_ci,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `webhook_deliveries_uuid_unique` (`uuid`),
  KEY `webhook_deliveries_webhook_endpoint_id_id_index` (`webhook_endpoint_id`,`id`),
  KEY `webhook_deliveries_client_id_created_at_index` (`client_id`,`created_at`),
  CONSTRAINT `webhook_deliveries_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `webhook_deliveries_webhook_endpoint_id_foreign` FOREIGN KEY (`webhook_endpoint_id`) REFERENCES `webhook_endpoints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webhook_endpoints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_endpoints` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `events` json NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `disabled_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',
  `last_triggered_at` timestamp NULL DEFAULT NULL,
  `disabled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `webhook_endpoints_uuid_unique` (`uuid`),
  KEY `webhook_endpoints_user_id_foreign` (`user_id`),
  KEY `webhook_endpoints_client_id_active_index` (`client_id`,`active`),
  CONSTRAINT `webhook_endpoints_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `webhook_endpoints_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_08_14_170933_add_two_factor_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_01_20_180128_create_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_01_20_180138_create_lists_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_01_20_180141_create_tags_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_01_20_180144_create_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_01_20_180147_create_contact_tag_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_01_20_180150_create_contact_list_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_01_20_182434_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_01_20_184810_create_contact_attributes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_01_20_191321_create_email_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_01_20_212640_create_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_01_20_220119_create_automations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_01_20_224638_create_blacklisted_emails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_01_20_231258_create_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_01_21_171853_create_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_01_21_171856_create_sequence_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_01_21_181405_create_contact_sequence_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_01_24_231221_create_contact_imports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_01_26_204949_create_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_01_28_212953_create_email_identity_dns_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_30_181432_name_column_can_be_null_in_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_01_31_164241_add_template_name_to_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_01_201617_add_ses_quota_fields_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_02_214159_remove_list_id_from_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_04_144201_create_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_04_144202_create_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_04_144203_add_meter_id_to_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_04_144204_add_meter_event_name_to_subscription_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_04_144214_create_customer_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_05_150331_add_role_column_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_02_09_174314_add_is_live_to_automations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_02_09_230454_add_filters_to_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_02_13_192412_add_delay_type_to_sequence_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_02_13_202647_add_clicked_at_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_02_13_213309_create_contact_mail_clicks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_02_20_200917_add_title_to_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_02_21_174521_create_media_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_02_23_165159_add_content_variables_to_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_02_23_165159_add_content_variables_to_sequences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_02_24_223536_create_user_storage_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_02_25_224055_add_ab_test_columns_to_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_02_25_224055_add_message_id_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_02_25_224055_add_variant_to_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_02_27_000001_create_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_27_000002_introduce_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_03_01_000000_add_total_contacts_reached_to_newsletters_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_03_03_144617_add_opt_in_columns_to_lists_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_03_03_144621_add_opted_in_at_to_contact_list_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_03_03_144625_backfill_opted_in_at_on_contact_list_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_03_04_230252_add_client_id_column_to_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_03_04_231408_add_purpose_to_email_identity_dns_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_03_05_213136_add_uuid_and_invite_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_03_05_221000_add_archived_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_03_10_232651_create_visual_automations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_03_10_232654_create_visual_automation_enrollments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_03_10_232657_create_visual_automation_contact_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_03_13_183358_add_role_to_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_03_15_042158_add_tracking_settings_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_03_16_234840_add_custom_tracking_domain_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_03_21_200653_create_blocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_03_22_000000_add_ses_permissions_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_03_24_205240_add_permission_notification_tracking_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_25_193813_create_email_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_26_025708_add_open_geo_tracking_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_26_025708_add_timezone_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_31_163344_add_configuration_set_to_email_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_04_06_143629_move_timezone_from_users_to_clients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_04_06_151843_drop_timezone_from_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_04_07_154632_create_email_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_04_08_000001_add_tenant_columns_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_04_08_000002_add_tenant_associated_at_to_email_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_04_08_184854_add_client_id_index_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_04_15_120001_create_topics_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_04_15_120002_create_contact_topic_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_04_16_053523_create_test_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_04_23_100000_add_is_default_to_email_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_04_23_100001_add_template_fields_to_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_04_23_100003_backfill_messages_with_default_template',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_04_24_155834_make_messages_subject_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_05_16_204741_add_custom_link_domain_to_email_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_05_21_120000_add_index_to_custom_tracking_domain',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_05_22_111351_add_preview_text_to_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_06_25_000001_add_completed_at_to_contact_sequence_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_06_25_000002_create_webhook_endpoints_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_06_25_000003_create_webhook_deliveries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_06_25_100000_create_segments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_07_01_154754_add_client_mailable_index_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_07_21_000001_add_default_value_to_contact_attributes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_07_21_210926_create_projects_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_07_23_143554_create_projectables_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_09_19_000001_add_email_index_to_blacklisted_emails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_09_22_000001_add_soft_bounce_count_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_09_22_000001_add_sunset_inactive_days_to_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_09_22_000002_add_engagement_columns_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_09_22_000003_backfill_contacts_last_engaged_at',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_09_22_000004_add_sunset_index_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_09_22_233133_add_enforcement_status_to_email_credentials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_09_22_233627_add_reserved_at_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_09_22_233628_add_sequence_due_index_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_09_22_233714_add_reply_to_to_email_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_09_22_234136_add_client_sent_at_index_to_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_09_22_234137_drop_client_id_index_from_contact_mails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_09_23_153418_add_filters_to_sequence_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_11_16_000001_drop_billing_tables_and_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_11_16_000002_add_postal_address_to_clients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_11_17_000001_add_is_owner_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_11_17_000002_create_instance_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_11_17_000003_add_deactivated_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_11_18_000001_add_client_foreign_keys',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_11_19_171019_add_last_dispatched_contact_id_to_newsletters_table',1);
