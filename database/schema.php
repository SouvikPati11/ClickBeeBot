<?php

/**
 * Canonical database schema.
 *
 * Returns an ordered list of DDL statements executed by the install wizard and
 * the migration runner. All tables are InnoDB / utf8mb4 with explicit indexes
 * and foreign keys. Financial history tables are never dropped by migrations.
 *
 * Note: `campaign_task_types` is the dynamic, admin-editable task-settings
 * table required by the spec — enabling future task types without code edits.
 */

declare(strict_types=1);

return [

    // 1. Web admin accounts (Super Admin Panel login).
    'admins' => "CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(64) NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `telegram_id` BIGINT NULL,
        `role` ENUM('super','manager','support') NOT NULL DEFAULT 'super',
        `last_login_at` DATETIME NULL,
        `last_login_ip` VARCHAR(45) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_admins_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Workers / users.
    'users' => "CREATE TABLE IF NOT EXISTS `users` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `telegram_id` BIGINT NOT NULL,
        `username` VARCHAR(64) NULL,
        `first_name` VARCHAR(128) NULL,
        `last_name` VARCHAR(128) NULL,
        `language` VARCHAR(8) NOT NULL DEFAULT 'en',
        `referral_code` VARCHAR(16) NOT NULL,
        `referred_by` BIGINT UNSIGNED NULL,
        `available_balance` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `pending_balance` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `referral_balance` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `total_earned` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `total_deposit` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `total_withdraw` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `completed_tasks` INT UNSIGNED NOT NULL DEFAULT 0,
        `pending_tasks` INT UNSIGNED NOT NULL DEFAULT 0,
        `rejected_tasks` INT UNSIGNED NOT NULL DEFAULT 0,
        `auto_approved_tasks` INT UNSIGNED NOT NULL DEFAULT 0,
        `notification_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `is_advertiser` TINYINT(1) NOT NULL DEFAULT 0,
        `status` ENUM('active','banned','deleted') NOT NULL DEFAULT 'active',
        `join_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `last_active` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_users_telegram_id` (`telegram_id`),
        UNIQUE KEY `uq_users_referral_code` (`referral_code`),
        KEY `idx_users_referred_by` (`referred_by`),
        KEY `idx_users_status` (`status`),
        KEY `idx_users_last_active` (`last_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. Advertiser profile (a user who advertises).
    'advertisers' => "CREATE TABLE IF NOT EXISTS `advertisers` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `display_name` VARCHAR(128) NULL,
        `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
        `language` VARCHAR(8) NOT NULL DEFAULT 'en',
        `notify` TINYINT(1) NOT NULL DEFAULT 1,
        `status` ENUM('active','paused','banned') NOT NULL DEFAULT 'active',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_advertisers_user` (`user_id`),
        KEY `idx_advertisers_status` (`status`),
        CONSTRAINT `fk_advertisers_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 4. Advertiser wallet (USD balances).
    'advertiser_wallet' => "CREATE TABLE IF NOT EXISTS `advertiser_wallet` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `advertiser_id` BIGINT UNSIGNED NOT NULL,
        `balance` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `spent` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `bonus` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_wallet_advertiser` (`advertiser_id`),
        CONSTRAINT `fk_wallet_advertiser` FOREIGN KEY (`advertiser_id`) REFERENCES `advertisers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 5. Dynamic task types + per-type admin settings (task_settings).
    'campaign_task_types' => "CREATE TABLE IF NOT EXISTS `campaign_task_types` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `type_key` VARCHAR(48) NOT NULL,
        `name` VARCHAR(96) NOT NULL,
        `icon` VARCHAR(16) NOT NULL DEFAULT '',
        `enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `min_cpc` DECIMAL(18,6) NOT NULL DEFAULT 0.001000,
        `min_daily_budget` DECIMAL(18,6) NOT NULL DEFAULT 0.100000,
        `platform_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
        `verification_type` ENUM('timer','membership','forward','manual','post_view') NOT NULL DEFAULT 'timer',
        `pending_hours` INT UNSIGNED NOT NULL DEFAULT 0,
        `auto_approval_hours` INT UNSIGNED NOT NULL DEFAULT 24,
        `timer_seconds` INT UNSIGNED NOT NULL DEFAULT 10,
        `handler_class` VARCHAR(128) NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_task_type_key` (`type_key`),
        KEY `idx_task_type_enabled` (`enabled`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 6. Campaigns.
    'campaigns' => "CREATE TABLE IF NOT EXISTS `campaigns` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `advertiser_id` BIGINT UNSIGNED NOT NULL,
        `task_type_id` INT UNSIGNED NOT NULL,
        `task_type_key` VARCHAR(48) NOT NULL,
        `title` VARCHAR(160) NOT NULL,
        `description` TEXT NULL,
        `cpc` DECIMAL(18,6) NOT NULL,
        `platform_fee_percent` DECIMAL(5,2) NOT NULL,
        `worker_reward` DECIMAL(18,6) NOT NULL,
        `daily_budget` DECIMAL(18,6) NOT NULL,
        `total_budget` DECIMAL(18,6) NOT NULL,
        `remaining_budget` DECIMAL(18,6) NOT NULL,
        `spent_amount` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `target_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `completed_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `pending_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `rejected_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `status` ENUM('draft','pending_payment','active','paused','completed','expired','cancelled') NOT NULL DEFAULT 'draft',
        `start_date` DATETIME NULL,
        `end_date` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_campaigns_advertiser` (`advertiser_id`),
        KEY `idx_campaigns_status` (`status`),
        KEY `idx_campaigns_type` (`task_type_key`),
        KEY `idx_campaigns_status_budget` (`status`, `remaining_budget`),
        CONSTRAINT `fk_campaigns_advertiser` FOREIGN KEY (`advertiser_id`) REFERENCES `advertisers`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_campaigns_task_type` FOREIGN KEY (`task_type_id`) REFERENCES `campaign_task_types`(`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 7. Campaign type-specific content.
    'campaign_contents' => "CREATE TABLE IF NOT EXISTS `campaign_contents` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` BIGINT UNSIGNED NOT NULL,
        `channel_username` VARCHAR(64) NULL,
        `channel_id` BIGINT NULL,
        `bot_username` VARCHAR(64) NULL,
        `bot_id` BIGINT NULL,
        `website_url` VARCHAR(512) NULL,
        `play_store_url` VARCHAR(512) NULL,
        `task_url` VARCHAR(512) NULL,
        `platform` VARCHAR(48) NULL,
        `source_chat_id` BIGINT NULL,
        `source_message_id` BIGINT NULL,
        `proof_type` ENUM('screenshot','text','username','email','custom','none') NOT NULL DEFAULT 'none',
        `verification_type` VARCHAR(24) NOT NULL DEFAULT 'timer',
        `timer_seconds` INT UNSIGNED NOT NULL DEFAULT 10,
        `extra` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_content_campaign` (`campaign_id`),
        CONSTRAINT `fk_content_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 8. Task submissions (one attempt per user per campaign).
    'task_submissions' => "CREATE TABLE IF NOT EXISTS `task_submissions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` BIGINT UNSIGNED NOT NULL,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `reward` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `proof` TEXT NULL,
        `proof_type` VARCHAR(24) NOT NULL DEFAULT 'none',
        `status` ENUM('pending','pending_review','approved','rejected','cancelled','skipped') NOT NULL DEFAULT 'pending',
        `submitted_at` DATETIME NULL,
        `reviewed_at` DATETIME NULL,
        `reviewer_id` BIGINT NULL,
        `reject_reason` VARCHAR(255) NULL,
        `auto_approved` TINYINT(1) NOT NULL DEFAULT 0,
        `verify_after` DATETIME NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_submission_user_campaign` (`campaign_id`, `user_id`),
        KEY `idx_submission_user` (`user_id`),
        KEY `idx_submission_status` (`status`),
        KEY `idx_submission_verify_after` (`verify_after`),
        CONSTRAINT `fk_submission_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_submission_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 9. Task history (immutable record of completed tasks).
    'task_history' => "CREATE TABLE IF NOT EXISTS `task_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `campaign_id` BIGINT UNSIGNED NOT NULL,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `task_type_key` VARCHAR(48) NOT NULL,
        `reward` DECIMAL(18,6) NOT NULL,
        `status` VARCHAR(24) NOT NULL,
        `completed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_history_user` (`user_id`),
        KEY `idx_history_campaign` (`campaign_id`),
        KEY `idx_history_completed` (`completed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 10. Deposits (Oxapay crypto -> USD balance).
    'deposits' => "CREATE TABLE IF NOT EXISTS `deposits` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `account_type` ENUM('user','advertiser') NOT NULL DEFAULT 'user',
        `amount` DECIMAL(18,6) NOT NULL,
        `currency` VARCHAR(16) NOT NULL DEFAULT 'USDT',
        `gateway` VARCHAR(32) NOT NULL DEFAULT 'oxapay',
        `invoice_id` VARCHAR(128) NULL,
        `transaction_id` VARCHAR(128) NULL,
        `status` ENUM('pending','paid','expired','failed') NOT NULL DEFAULT 'pending',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `completed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_deposits_user` (`user_id`),
        KEY `idx_deposits_status` (`status`),
        UNIQUE KEY `uq_deposits_invoice` (`invoice_id`),
        CONSTRAINT `fk_deposits_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 11. Withdrawals (manual review).
    'withdrawals' => "CREATE TABLE IF NOT EXISTS `withdrawals` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `method` ENUM('usdt_bep20','binance_uid') NOT NULL,
        `wallet_address` VARCHAR(128) NULL,
        `binance_uid` VARCHAR(32) NULL,
        `amount` DECIMAL(18,6) NOT NULL,
        `fee` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `net_amount` DECIMAL(18,6) NOT NULL,
        `status` ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
        `transaction_id` VARCHAR(128) NULL,
        `admin_note` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `completed_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_withdrawals_user` (`user_id`),
        KEY `idx_withdrawals_status` (`status`),
        CONSTRAINT `fk_withdrawals_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 12. Referral links.
    'referrals' => "CREATE TABLE IF NOT EXISTS `referrals` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `referrer_id` BIGINT UNSIGNED NOT NULL,
        `referred_id` BIGINT UNSIGNED NOT NULL,
        `deposit_commission` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `task_commission` DECIMAL(18,6) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_referrals_pair` (`referrer_id`, `referred_id`),
        KEY `idx_referrals_referrer` (`referrer_id`),
        CONSTRAINT `fk_referrals_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_referrals_referred` FOREIGN KEY (`referred_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 13. Referral income history.
    'referral_history' => "CREATE TABLE IF NOT EXISTS `referral_history` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `referrer_id` BIGINT UNSIGNED NOT NULL,
        `referred_id` BIGINT UNSIGNED NOT NULL,
        `amount` DECIMAL(18,6) NOT NULL,
        `source` VARCHAR(48) NOT NULL,
        `commission_type` ENUM('deposit','task') NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_refhistory_referrer` (`referrer_id`),
        KEY `idx_refhistory_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 14. Transactions (every balance change, immutable ledger).
    'transactions' => "CREATE TABLE IF NOT EXISTS `transactions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `type` ENUM('deposit','withdraw','task_reward','pending_reward','referral','adjustment','platform_bonus','balance_reversal','campaign_spend','campaign_refund') NOT NULL,
        `amount` DECIMAL(18,6) NOT NULL,
        `balance_after` DECIMAL(18,6) NULL,
        `reference` VARCHAR(64) NULL,
        `description` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_transactions_user` (`user_id`),
        KEY `idx_transactions_type` (`type`),
        KEY `idx_transactions_created` (`created_at`),
        CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 15. Advertiser transactions.
    'advertiser_transactions' => "CREATE TABLE IF NOT EXISTS `advertiser_transactions` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `advertiser_id` BIGINT UNSIGNED NOT NULL,
        `type` ENUM('deposit','campaign_payment','refund','adjustment','bonus') NOT NULL,
        `amount` DECIMAL(18,6) NOT NULL,
        `campaign_id` BIGINT UNSIGNED NULL,
        `description` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_advtx_advertiser` (`advertiser_id`),
        KEY `idx_advtx_type` (`type`),
        CONSTRAINT `fk_advtx_advertiser` FOREIGN KEY (`advertiser_id`) REFERENCES `advertisers`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 16. Notifications.
    'notifications' => "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `title` VARCHAR(160) NOT NULL,
        `message` TEXT NOT NULL,
        `type` VARCHAR(48) NOT NULL DEFAULT 'system',
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_notifications_user` (`user_id`, `is_read`),
        KEY `idx_notifications_created` (`created_at`),
        CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 17. Settings (admin-editable key/value).
    'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `setting_key` VARCHAR(96) NOT NULL,
        `setting_value` TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_settings_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 18. Conversation state machine for multi-step bot flows.
    'user_states' => "CREATE TABLE IF NOT EXISTS `user_states` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `telegram_id` BIGINT NOT NULL,
        `scope` VARCHAR(32) NOT NULL DEFAULT 'user',
        `state` VARCHAR(64) NULL,
        `payload` TEXT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_state_telegram_scope` (`telegram_id`, `scope`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 19. Admin action audit log.
    'admin_logs' => "CREATE TABLE IF NOT EXISTS `admin_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `admin_id` INT UNSIGNED NULL,
        `action` VARCHAR(160) NOT NULL,
        `details` TEXT NULL,
        `ip` VARCHAR(45) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_adminlogs_admin` (`admin_id`),
        KEY `idx_adminlogs_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 20. Backups.
    'backups' => "CREATE TABLE IF NOT EXISTS `backups` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(160) NOT NULL,
        `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `path` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_backups_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 21. Cron execution logs.
    'cron_logs' => "CREATE TABLE IF NOT EXISTS `cron_logs` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `job` VARCHAR(64) NOT NULL,
        `status` ENUM('success','failed') NOT NULL,
        `message` VARCHAR(255) NULL,
        `duration_ms` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_cronlogs_job` (`job`),
        KEY `idx_cronlogs_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 22. Support tickets.
    'support_tickets' => "CREATE TABLE IF NOT EXISTS `support_tickets` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` BIGINT UNSIGNED NOT NULL,
        `subject` VARCHAR(160) NOT NULL,
        `message` TEXT NULL,
        `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
        `priority` ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_tickets_user` (`user_id`),
        KEY `idx_tickets_status` (`status`),
        CONSTRAINT `fk_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 23. Review requests (worker disputes a rejection -> super admin).
    'reviews' => "CREATE TABLE IF NOT EXISTS `reviews` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `submission_id` BIGINT UNSIGNED NOT NULL,
        `reason` VARCHAR(255) NULL,
        `admin_decision` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `decided_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `decided_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        KEY `idx_reviews_submission` (`submission_id`),
        KEY `idx_reviews_decision` (`admin_decision`),
        CONSTRAINT `fk_reviews_submission` FOREIGN KEY (`submission_id`) REFERENCES `task_submissions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 24. Schema migration tracking.
    'migrations' => "CREATE TABLE IF NOT EXISTS `migrations` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `version` VARCHAR(64) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_migrations_version` (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
