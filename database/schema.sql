-- FreeSub SaaS Platform - MySQL 8+ Database Schema
-- Charset: utf8mb4 / Collation: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- 1. System Settings
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('default_free_subdomain_limit', '2'),
    ('max_dns_records_per_subdomain', '10'),
    ('custom_reserved_subdomains', '')
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`);

-- -----------------------------------------------------------------------------
-- 2. Users (Synced with Clerk Auth)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `clerk_user_id` VARCHAR(191) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `full_name` VARCHAR(255) DEFAULT NULL,
    `plan` ENUM('free', 'pro') NOT NULL DEFAULT 'free',
    `custom_subdomain_limit` INT DEFAULT NULL,
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_clerk_user_id` (`clerk_user_id`),
    KEY `idx_users_email` (`email`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Root Domains (Managed via Cloudflare Zones)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `root_domains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_name` VARCHAR(253) NOT NULL,
    `cloudflare_zone_id` VARCHAR(64) NOT NULL,
    `cloudflare_api_token` VARCHAR(512) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `description` VARCHAR(500) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_root_domains_domain_name` (`domain_name`),
    KEY `idx_root_domains_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Subdomains (Claimed by Users under Root Domains)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subdomains` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `root_domain_id` BIGINT UNSIGNED NOT NULL,
    `subdomain_name` VARCHAR(63) NOT NULL,
    `full_domain` VARCHAR(253) NOT NULL,
    `status` ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    `ssl_status` ENUM('active', 'pending') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subdomains_full_domain` (`full_domain`),
    UNIQUE KEY `uq_subdomains_root_label` (`root_domain_id`, `subdomain_name`),
    KEY `idx_subdomains_user_id` (`user_id`),
    KEY `idx_subdomains_status` (`status`),
    CONSTRAINT `fk_subdomains_user_id`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_subdomains_root_domain_id`
        FOREIGN KEY (`root_domain_id`) REFERENCES `root_domains` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. DNS Records (Provisioned on Cloudflare)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dns_records` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subdomain_id` BIGINT UNSIGNED NOT NULL,
    `cf_record_id` VARCHAR(64) DEFAULT NULL,
    `record_type` ENUM('A', 'AAAA', 'CNAME', 'MX', 'TXT') NOT NULL,
    `record_name` VARCHAR(253) NOT NULL,
    `target_value` TEXT NOT NULL,
    `proxied` TINYINT(1) NOT NULL DEFAULT 0,
    `ttl` INT NOT NULL DEFAULT 1,
    `priority` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dns_records_subdomain_id` (`subdomain_id`),
    KEY `idx_dns_records_cf_record_id` (`cf_record_id`),
    KEY `idx_dns_records_record_type` (`record_type`),
    CONSTRAINT `fk_dns_records_subdomain_id`
        FOREIGN KEY (`subdomain_id`) REFERENCES `subdomains` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
