<?php

/**
 * Default seed data applied once during installation.
 *
 * These rows make every feature editable from the Admin Panel out of the box
 * without hardcoding a single value in PHP. The install wizard overrides the
 * bot/payment specific keys with the operator's real input.
 */

declare(strict_types=1);

return [
    // key => value. All admin-editable at runtime via the settings table.
    'settings' => [
        'bot_name'            => 'ClickBee',
        'bot_username'        => '',
        'support_username'    => '',
        'official_channel'    => '',
        'website_url'         => '',
        'currency'            => 'USD',
        'currency_symbol'     => '$',
        'timezone'            => 'UTC',
        'language'            => 'en',
        'maintenance_mode'    => '0',

        // Referral.
        'referral_enabled'         => '1',
        'deposit_commission_pct'   => '5',
        'task_commission_pct'      => '5',
        'min_referral_payout'      => '0.5',

        // Deposit / withdraw.
        'min_deposit'         => '1',
        'min_withdraw'        => '2',
        'max_daily_withdraw'  => '100',
        'withdraw_fee_enabled'=> '0',
        'withdraw_fee_type'   => 'percentage',
        'withdraw_fee_value'  => '0',

        // Oxapay.
        'oxapay_enabled'       => '0',
        'oxapay_merchant_key'  => '',
        'oxapay_payout_key'    => '',
        'oxapay_webhook_secret'=> '',
        'oxapay_environment'   => 'production',

        // Info pages (admin editable).
        'info_about'   => 'Welcome to our earning platform.',
        'info_rules'   => 'Complete tasks honestly. Fraud leads to a ban.',
        'info_terms'   => 'By using this bot you agree to our terms.',

        // Backups.
        'backup_enabled'   => '0',
        'backup_frequency' => 'daily',
        'backup_max_files' => '7',
    ],

    // Default modular task types. Admin can enable/disable and tune each.
    'task_types' => [
        ['type_key' => 'visit_website', 'name' => 'Visit Sites',   'icon' => '💻', 'verification_type' => 'timer',      'pending_hours' => 0,  'auto_approval_hours' => 0,  'timer_seconds' => 10, 'sort_order' => 1,  'handler_class' => 'App\\Tasks\\Types\\VisitWebsiteTask'],
        ['type_key' => 'join_channel', 'name' => 'Join Channels',  'icon' => '📢', 'verification_type' => 'membership', 'pending_hours' => 24, 'auto_approval_hours' => 0,  'timer_seconds' => 0,  'sort_order' => 2,  'handler_class' => 'App\\Tasks\\Types\\JoinChannelTask'],
        ['type_key' => 'join_bot',     'name' => 'Join Bots',      'icon' => '🤖', 'verification_type' => 'forward',    'pending_hours' => 0,  'auto_approval_hours' => 0,  'timer_seconds' => 0,  'sort_order' => 3,  'handler_class' => 'App\\Tasks\\Types\\JoinBotTask'],
        ['type_key' => 'view_posts',   'name' => 'View Posts',     'icon' => '📄', 'verification_type' => 'post_view',  'pending_hours' => 0,  'auto_approval_hours' => 0,  'timer_seconds' => 10, 'sort_order' => 4,  'handler_class' => 'App\\Tasks\\Types\\ViewPostsTask'],
        ['type_key' => 'app_download', 'name' => 'App Download',   'icon' => '📱', 'verification_type' => 'manual',     'pending_hours' => 0,  'auto_approval_hours' => 24, 'timer_seconds' => 0,  'sort_order' => 5,  'handler_class' => 'App\\Tasks\\Types\\ManualTask'],
        ['type_key' => 'app_review',   'name' => 'App Review',     'icon' => '⭐', 'verification_type' => 'manual',     'pending_hours' => 0,  'auto_approval_hours' => 24, 'timer_seconds' => 0,  'sort_order' => 6,  'handler_class' => 'App\\Tasks\\Types\\ManualTask'],
        ['type_key' => 'social_media', 'name' => 'Social Media',   'icon' => '👥', 'verification_type' => 'manual',     'pending_hours' => 0,  'auto_approval_hours' => 24, 'timer_seconds' => 0,  'sort_order' => 7,  'handler_class' => 'App\\Tasks\\Types\\ManualTask'],
    ],
];
