-- Test schema fixture generated from the current idc structure. No business data is included.
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `account_transactions`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `admin_user_roles`;
DROP TABLE IF EXISTS `admin_users`;
DROP TABLE IF EXISTS `agent_applications`;
DROP TABLE IF EXISTS `archive_audit_logs`;
DROP TABLE IF EXISTS `automation_logs`;
DROP TABLE IF EXISTS `content_articles`;
DROP TABLE IF EXISTS `content_categories`;
DROP TABLE IF EXISTS `coupon_campaigns`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `first_product_groups`;
DROP TABLE IF EXISTS `gateway_logs`;
DROP TABLE IF EXISTS `integration_plugin_bindings`;
DROP TABLE IF EXISTS `integration_plugin_configs`;
DROP TABLE IF EXISTS `integration_plugin_runtime_logs`;
DROP TABLE IF EXISTS `integration_plugins`;
DROP TABLE IF EXISTS `invoice_items`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `media_files`;
DROP TABLE IF EXISTS `member_levels`;
DROP TABLE IF EXISTS `message_logs`;
DROP TABLE IF EXISTS `migrations`;
DROP TABLE IF EXISTS `notice_reads`;
DROP TABLE IF EXISTS `notification_templates`;
DROP TABLE IF EXISTS `operation_logs`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `payment_callbacks`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `personal_access_tokens`;
DROP TABLE IF EXISTS `product_upstream_bindings`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `referral_account_logs`;
DROP TABLE IF EXISTS `referral_rewards`;
DROP TABLE IF EXISTS `referral_withdrawals`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `schedule_run_logs`;
DROP TABLE IF EXISTS `schedule_task_runs`;
DROP TABLE IF EXISTS `schedule_ticks`;
DROP TABLE IF EXISTS `second_product_groups`;
DROP TABLE IF EXISTS `service_connection_snapshots`;
DROP TABLE IF EXISTS `service_provision_attempts`;
DROP TABLE IF EXISTS `service_runtime_snapshots`;
DROP TABLE IF EXISTS `service_upstream_bindings`;
DROP TABLE IF EXISTS `services`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `supplier_plugin_bindings`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `third_product_groups`;
DROP TABLE IF EXISTS `ticket_replies`;
DROP TABLE IF EXISTS `tickets`;
DROP TABLE IF EXISTS `user_accounts`;
DROP TABLE IF EXISTS `user_coupons`;
DROP TABLE IF EXISTS `user_notifications`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `verification_histories`;

CREATE TABLE `account_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '账户流水自增主键',
  `user_id` bigint unsigned NOT NULL COMMENT '所属用户ID',
  `account_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '账户类型：cash/credit/referral 等',
  `event_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '流水事件类型：recharge/consume/refund/adjust/reward_frozen/reward_released 等',
  `change_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '本次变动金额，收入为正、支出为负',
  `balance_after` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '本次变动后的账户余额',
  `source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务来源类型，如 invoice/payment/referral_withdrawal',
  `source_id` bigint unsigned DEFAULT NULL COMMENT '业务来源ID',
  `origin_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '原始触发对象类型，用于跨域追踪',
  `origin_id` bigint unsigned DEFAULT NULL COMMENT '原始触发对象ID',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '流水备注',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人快照',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `account_transactions_user_account_created_idx` (`user_id`,`account_type`,`created_at`,`id`),
  KEY `account_transactions_user_event_created_idx` (`user_id`,`event_type`,`created_at`),
  KEY `account_transactions_origin_idx` (`origin_type`,`origin_id`),
  KEY `account_transactions_trace_id_idx` (`trace_id`),
  KEY `account_transactions_created_at_idx` (`created_at`),
  CONSTRAINT `fk_stage2_account_transactions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=1741 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户流水表，记录现金账户、授信账户、推荐奖励账户的每一次余额变化';

CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system' COMMENT '操作者类型: admin, client, system, sub_account',
  `actor_id` bigint unsigned DEFAULT NULL COMMENT '操作者ID',
  `actor_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '操作者名称快照',
  `module` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模块: invoice, order, service, user, product, ticket, coupon, system',
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '动作描述: create, pay, refund, suspend, terminate 等',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '可读描述',
  `subject_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '关联对象类型: invoice, service, order, user, ticket',
  `subject_id` bigint unsigned DEFAULT NULL COMMENT '关联对象ID',
  `context` json DEFAULT NULL COMMENT '附加结构化上下文',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_module_action_index` (`module`,`action`),
  KEY `activity_logs_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `activity_logs_created_at_index` (`created_at`),
  KEY `activity_logs_actor_id_index` (`actor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16846 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_user_roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint unsigned NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_user_roles_admin_role_unique` (`admin_user_id`,`role_id`),
  KEY `admin_user_roles_role_id_idx` (`role_id`),
  CONSTRAINT `fk_stage2_admin_user_roles_admin_user_id` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stage2_admin_user_roles_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=146 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_id` bigint unsigned NOT NULL,
  `nickname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '0=禁用 1=正常',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_users_username_unique` (`username`),
  KEY `admin_users_role_id_index` (`role_id`),
  KEY `admin_users_email_index` (`email`),
  CONSTRAINT `fk_stage2_admin_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=313 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `agent_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `contact_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系人',
  `contact_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '联系手机',
  `contact_qq` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'QQ号',
  `company_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '公司名称',
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '申请说明',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/approved/rejected',
  `api_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'API密钥',
  `admin_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '管理员备注',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_applications_user_id_foreign` (`user_id`),
  KEY `agent_applications_status_index` (`status`),
  CONSTRAINT `agent_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `archive_audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `row_count` int unsigned NOT NULL DEFAULT '0',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `checksum_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `archive_batch_idx` (`batch_id`),
  KEY `archive_table_status_idx` (`table_name`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `automation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` bigint unsigned NOT NULL,
  `rule_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `meta` json DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `automation_logs_unique_rule` (`task_key`,`action`,`object_type`,`object_id`,`rule_key`),
  KEY `automation_logs_object_idx` (`object_type`,`object_id`),
  KEY `automation_logs_task_key_index` (`task_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1049 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `content_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'notice|help',
  `category_id` bigint unsigned DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(220) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `keywords` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=草稿 1=已发布 2=已下线',
  `is_pinned` tinyint NOT NULL DEFAULT '0',
  `is_recommended` tinyint NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `require_reread_at` timestamp NULL DEFAULT NULL,
  `publish_at` timestamp NULL DEFAULT NULL,
  `last_published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_articles_slug_unique` (`slug`),
  KEY `idx_content_type_status_publish` (`content_type`,`status`,`publish_at`),
  KEY `idx_content_type_pin_sort` (`content_type`,`is_pinned`,`sort_order`,`id`),
  KEY `idx_content_type_recommend` (`content_type`,`is_recommended`,`publish_at`),
  KEY `idx_content_category_type` (`category_name`,`content_type`),
  KEY `content_articles_created_by_index` (`created_by`),
  KEY `content_articles_updated_by_index` (`updated_by`),
  KEY `idx_content_article_type_category` (`content_type`,`category_id`),
  KEY `idx_article_published` (`status`,`publish_at`,`is_pinned`),
  KEY `idx_article_category_published` (`category_id`,`status`,`publish_at`),
  CONSTRAINT `fk_stage2_content_articles_category_id` FOREIGN KEY (`category_id`) REFERENCES `content_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `content_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'notice|help',
  `name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '0=禁用 1=启用',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_content_category_type_name` (`content_type`,`name`),
  UNIQUE KEY `uniq_content_category_type_slug` (`content_type`,`slug`),
  KEY `idx_content_category_type_status_sort` (`content_type`,`status`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupon_campaigns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weekdays` json DEFAULT NULL,
  `trigger_time` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_quantity` int unsigned NOT NULL DEFAULT '1',
  `valid_duration_hours` int unsigned DEFAULT NULL,
  `discount_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_month',
  `discount_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `discount_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `min_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_discount_amount` decimal(12,2) DEFAULT NULL,
  `billing_cycles` json DEFAULT NULL,
  `product_ids` json DEFAULT NULL,
  `first_order_only` tinyint(1) NOT NULL DEFAULT '0',
  `per_user_limit` int unsigned DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `last_dispatched_at` timestamp NULL DEFAULT NULL,
  `last_coupon_id` bigint unsigned DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `coupon_campaigns_status_sort_idx` (`status`,`sort_order`),
  KEY `coupon_campaigns_trigger_status_idx` (`trigger_time`,`status`),
  KEY `idx_stage2_coupon_campaigns_last_coupon_id` (`last_coupon_id`),
  CONSTRAINT `fk_stage2_coupon_campaigns_last_coupon_id` FOREIGN KEY (`last_coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `coupons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `coupon_campaign_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `distribution_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `discount_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_month',
  `discount_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `discount_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `min_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_discount_amount` decimal(12,2) DEFAULT NULL,
  `billing_cycles` json DEFAULT NULL,
  `product_ids` json DEFAULT NULL,
  `first_order_only` tinyint(1) NOT NULL DEFAULT '0',
  `total_usage_limit` int unsigned DEFAULT NULL,
  `per_user_limit` int unsigned DEFAULT NULL,
  `used_count` int unsigned NOT NULL DEFAULT '0',
  `status` tinyint NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `coupons_code_unique` (`code`),
  KEY `coupons_campaign_status_idx` (`coupon_campaign_id`,`status`),
  KEY `coupons_status_sort_idx` (`status`,`sort_order`),
  CONSTRAINT `fk_stage2_coupons_coupon_campaign_id` FOREIGN KEY (`coupon_campaign_id`) REFERENCES `coupon_campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `first_product_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务编码：vps/dedicated/domain/…',
  `product_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '商品类型：cloud_server/game_cloud/…',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL标识',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分组说明',
  `icon` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '图标',
  `banner_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '横幅图',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序',
  `is_visible` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '前台可见',
  `is_system` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '系统内置',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `first_product_groups_slug_unique` (`slug`),
  UNIQUE KEY `first_product_groups_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `gateway_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` bigint unsigned DEFAULT NULL,
  `gateway_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gateway` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '网关标识: alipay_f2f, wechat_native 等',
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '操作: precreate, notify, query, refund',
  `out_trade_no` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '商户订单号',
  `trade_no` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '第三方交易号',
  `invoice_id` bigint unsigned DEFAULT NULL COMMENT '关联账单ID',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_data` json DEFAULT NULL COMMENT '请求数据(脱敏后)',
  `response_data` json DEFAULT NULL COMMENT '响应数据',
  `result_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown' COMMENT '结果: success, failed, pending, unknown',
  `error_msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `gateway_logs_gateway_action_index` (`gateway`,`action`),
  KEY `gateway_logs_created_at_index` (`created_at`),
  KEY `gateway_logs_out_trade_no_index` (`out_trade_no`),
  KEY `gateway_logs_invoice_id_index` (`invoice_id`),
  KEY `gateway_logs_plugin_created_idx` (`plugin_id`,`created_at`),
  KEY `gateway_logs_gateway_key_idx` (`gateway_key`,`created_at`),
  KEY `gateway_logs_trace_idx` (`trace_id`),
  CONSTRAINT `fk_stage2_gateway_logs_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gateway_logs_plugin_fk` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_plugin_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plugin_id` bigint unsigned NOT NULL,
  `binding_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'global/supplier/product/service/payment/notification/custom',
  `bindable_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `bindable_id` bigint unsigned NOT NULL DEFAULT '0',
  `binding_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '同一对象下的绑定名',
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '外部协议标识快照',
  `priority` int NOT NULL DEFAULT '0',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `config_json` json DEFAULT NULL,
  `secret_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_secret_json` json DEFAULT NULL,
  `runtime_policy_json` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plugin_bindings_unique` (`domain`,`binding_type`,`bindable_type`,`bindable_id`,`binding_key`),
  KEY `plugin_bindings_plugin_status_idx` (`plugin_id`,`status`),
  KEY `plugin_bindings_domain_provider_status_idx` (`domain`,`provider_key`,`status`),
  KEY `plugin_bindings_bindable_idx` (`bindable_type`,`bindable_id`,`domain`),
  KEY `plugin_bindings_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `integration_plugin_bindings_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_plugin_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` bigint unsigned NOT NULL,
  `config_json` json DEFAULT NULL,
  `secret_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_secret_json` json DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_plugin_configs_plugin_unique` (`plugin_id`),
  CONSTRAINT `integration_plugin_configs_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_plugin_runtime_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plugin_id` bigint unsigned DEFAULT NULL,
  `plugin_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `binding_id` bigint unsigned DEFAULT NULL,
  `bindable_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bindable_id` bigint unsigned DEFAULT NULL,
  `actor_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `error_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_meta_json` json DEFAULT NULL,
  `response_meta_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `plugin_runtime_trace_idx` (`trace_id`),
  KEY `plugin_runtime_plugin_created_idx` (`plugin_id`,`created_at`),
  KEY `plugin_runtime_domain_action_created_idx` (`domain`,`action`,`created_at`),
  KEY `plugin_runtime_status_created_idx` (`status`,`created_at`),
  KEY `plugin_runtime_bindable_idx` (`bindable_type`,`bindable_id`,`created_at`),
  CONSTRAINT `integration_plugin_runtime_logs_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6749 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integration_plugins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plugin_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0.0',
  `provider_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `capabilities_json` json DEFAULT NULL,
  `config_schema_json` json DEFAULT NULL,
  `status` tinyint unsigned NOT NULL DEFAULT '0',
  `installed_at` timestamp NULL DEFAULT NULL,
  `enabled_at` timestamp NULL DEFAULT NULL,
  `disabled_at` timestamp NULL DEFAULT NULL,
  `installed_by` bigint unsigned DEFAULT NULL,
  `enabled_by` bigint unsigned DEFAULT NULL,
  `source_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_plugins_domain_slug_unique` (`domain`,`slug`),
  UNIQUE KEY `integration_plugins_domain_key_unique` (`domain`,`plugin_key`),
  KEY `integration_plugins_domain_status_index` (`domain`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `invoice_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '账单明细自增主键',
  `invoice_id` bigint unsigned NOT NULL COMMENT '所属账单ID',
  `item_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '明细名称',
  `item_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT '明细类型：normal/config/addon/discount/refund 等',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '明细数量',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '明细单价',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '明细优惠金额',
  `line_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '明细小计金额',
  `meta_json` json DEFAULT NULL COMMENT '明细扩展快照 JSON',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `invoice_items_invoice_id_index` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2770 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账单明细表，记录账单内每个收费项目和快照信息';

CREATE TABLE `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '账单自增主键',
  `invoice_no` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '业务账单号，对外展示和支付关联使用',
  `user_id` bigint unsigned NOT NULL COMMENT '所属用户ID',
  `order_id` bigint unsigned DEFAULT NULL COMMENT '内部订单/开通投影ID，仅用于流程追踪',
  `product_id` bigint unsigned DEFAULT NULL COMMENT '关联商品ID，手工账单可为空',
  `product_spec_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '账单生成时的商品规格展示快照',
  `product_type_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '账单生成时的商品类型快照',
  `service_id` bigint unsigned DEFAULT NULL COMMENT '关联服务实例ID',
  `coupon_id` bigint unsigned DEFAULT NULL COMMENT '使用的优惠券模板ID',
  `user_coupon_id` bigint unsigned DEFAULT NULL COMMENT '使用的用户优惠券ID',
  `coupon_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '使用的优惠码快照',
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT '账单类型：normal/new/renew/recharge/deduction/referral_credit/manual/upgrade',
  `amount` decimal(12,2) NOT NULL COMMENT '账单应收金额',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '账单优惠抵扣金额',
  `billing_cycle` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '计费周期：monthly/quarterly/annually/onetime 等',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT '购买数量或计费数量',
  `config_snapshot` json DEFAULT NULL COMMENT '下单配置快照 JSON',
  `config_pricing_snapshot` json DEFAULT NULL COMMENT '配置项计价快照 JSON',
  `coupon_snapshot` json DEFAULT NULL COMMENT '优惠券使用快照 JSON',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '已支付入账金额',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '账单状态：0待支付 1已支付 2已取消 3已逾期 5已退款',
  `due_date` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL COMMENT '账单支付完成时间',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `refund_trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '退款链路追踪号',
  `refund_method` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '退款方式',
  `refund_amount` decimal(12,2) DEFAULT NULL COMMENT '退款金额',
  `refunded_at` timestamp NULL DEFAULT NULL COMMENT '退款完成时间',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '账单备注',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人快照',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_invoice_no_unique` (`invoice_no`),
  KEY `invoices_user_id_index` (`user_id`),
  KEY `invoices_status_due_date_index` (`status`,`due_date`),
  KEY `invoices_user_status_id_idx` (`user_id`,`status`,`id`),
  KEY `invoices_status_paid_at_idx` (`status`,`paid_at`),
  KEY `invoices_order_id_idx` (`order_id`),
  KEY `invoices_trace_id_idx` (`trace_id`),
  KEY `invoices_product_id_idx` (`product_id`),
  KEY `invoices_service_id_idx` (`service_id`),
  KEY `invoices_user_status_created_idx` (`user_id`,`status`,`created_at`),
  KEY `fk_invoices_user_coupon_id` (`user_coupon_id`),
  KEY `idx_stage2_invoices_coupon_id` (`coupon_id`),
  CONSTRAINT `fk_invoices_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_invoices_user_coupon_id` FOREIGN KEY (`user_coupon_id`) REFERENCES `user_coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_invoices_coupon_id` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_invoices_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2987 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账单主表，所有购买、续费、充值、扣款和退款流程以账单为财务入口';

CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=882 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `media_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '相对路径，如 /uploads/content/20260419/cover_xxx.jpg',
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '完整访问 URL',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0' COMMENT '文件大小(字节)',
  `width` int unsigned DEFAULT NULL COMMENT '图片宽度',
  `height` int unsigned DEFAULT NULL COMMENT '图片高度',
  `group` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'content' COMMENT '分组: content, avatar, brand 等',
  `uploaded_by` bigint unsigned NOT NULL DEFAULT '0' COMMENT '上传管理员ID',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `media_files_group_index` (`group`),
  KEY `media_files_uploaded_by_index` (`uploaded_by`),
  KEY `media_files_created_at_index` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_levels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_amount_min` decimal(12,2) NOT NULL DEFAULT '0.00',
  `sales_amount_max` decimal(12,2) DEFAULT NULL,
  `reward_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '0=禁用 1=启用',
  `sort_order` int NOT NULL DEFAULT '0',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_levels_code_unique` (`code`),
  KEY `idx_member_level_status_sort` (`status`,`sort_order`),
  KEY `idx_member_level_sales_range` (`sales_amount_min`,`sales_amount_max`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '消息日志ID',
  `plugin_id` bigint unsigned DEFAULT NULL COMMENT '插件ID',
  `driver_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '驱动标识',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪ID',
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '消息渠道：email/sms',
  `recipient` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '接收人邮箱或手机号',
  `template_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '业务模板编码或供应商模板ID',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '邮件主题',
  `content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '发送内容快照',
  `params_json` json DEFAULT NULL COMMENT '渲染参数快照',
  `provider` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '供应商或驱动',
  `request_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '供应商请求ID',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '发送状态',
  `error_msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '失败原因',
  `sent_at` timestamp NULL DEFAULT NULL COMMENT '发送完成时间',
  `origin_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '来源类型快照',
  `origin_id` bigint unsigned DEFAULT NULL COMMENT '来源ID快照',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_logs_channel_created_at_idx` (`channel`,`created_at`),
  KEY `message_logs_recipient_created_at_idx` (`recipient`,`created_at`),
  KEY `message_logs_driver_created_idx` (`driver_key`,`created_at`),
  KEY `message_logs_plugin_created_idx` (`plugin_id`,`created_at`),
  KEY `message_logs_channel_driver_idx` (`channel`,`driver_key`,`created_at`),
  KEY `message_logs_origin_idx` (`origin_type`,`origin_id`),
  KEY `message_logs_trace_idx` (`trace_id`),
  KEY `message_logs_request_id_idx` (`request_id`),
  CONSTRAINT `message_logs_plugin_fk` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2302 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=164 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notice_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `article_id` bigint unsigned NOT NULL,
  `read_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notice_reads_user_id_article_id_unique` (`user_id`,`article_id`),
  KEY `notice_reads_user_id_index` (`user_id`),
  KEY `notice_reads_article_id_index` (`article_id`),
  CONSTRAINT `fk_stage2_notice_reads_article_id` FOREIGN KEY (`article_id`) REFERENCES `content_articles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stage2_notice_reads_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notification_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `audience` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variables_json` json DEFAULT NULL,
  `provider_variables_json` json DEFAULT NULL,
  `provider_template_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `is_custom` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_templates_channel_code_unique` (`channel`,`code`),
  KEY `notification_templates_channel_audience_enabled_index` (`channel`,`audience`,`is_enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=981 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `operation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'admin|client',
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `module` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `context` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `operation_logs_user_id_user_type_index` (`user_id`,`user_type`),
  KEY `operation_logs_module_created_at_index` (`module`,`created_at`),
  KEY `operation_logs_user_type_created_at_idx` (`user_id`,`user_type`,`created_at`),
  KEY `operation_logs_module_subject_created_idx` (`module`,`subject_id`,`created_at`,`id`),
  KEY `operation_logs_created_at_idx` (`created_at`),
  KEY `operation_logs_user_created_at_idx` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=148538 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `product_spec_snapshot` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_type_snapshot` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_id` bigint unsigned DEFAULT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'new|renew|upgrade|downgrade',
  `coupon_id` bigint unsigned DEFAULT NULL,
  `user_coupon_id` bigint unsigned DEFAULT NULL,
  `coupon_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `billing_cycle` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `config_snapshot` json DEFAULT NULL,
  `config_pricing_snapshot` json DEFAULT NULL,
  `coupon_snapshot` json DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=待付款 1=已付款 2=开通中 3=已完成 4=已取消 5=已退款',
  `paid_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '备注',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  `projection_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provisioning' COMMENT '内部投影类型：provisioning=开通投影',
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_no_unique` (`order_no`),
  KEY `orders_user_id_index` (`user_id`),
  KEY `orders_user_status_id_idx` (`user_id`,`status`,`id`),
  KEY `orders_created_at_idx` (`created_at`),
  KEY `orders_status_type_created_at_idx` (`status`,`type`,`created_at`,`id`),
  KEY `orders_coupon_id_idx` (`coupon_id`),
  KEY `orders_user_coupon_id_idx` (`user_coupon_id`),
  KEY `orders_trace_id_idx` (`trace_id`),
  KEY `orders_product_id_idx` (`product_id`),
  KEY `orders_service_status_id_idx` (`service_id`,`status`,`id`),
  KEY `orders_projection_type_idx` (`projection_type`),
  CONSTRAINT `fk_stage2_orders_coupon_id` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_orders_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_orders_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_orders_user_coupon_id` FOREIGN KEY (`user_coupon_id`) REFERENCES `user_coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3080 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_callbacks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '支付回调自增主键',
  `payment_id` bigint unsigned NOT NULL COMMENT '关联支付记录ID',
  `plugin_id` bigint unsigned DEFAULT NULL,
  `gateway_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `callback_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回调类型：notify/query/refund 等',
  `gateway_trade_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '第三方交易号',
  `payload_json` json DEFAULT NULL COMMENT '回调载荷 JSON',
  `is_verified` tinyint NOT NULL DEFAULT '0' COMMENT '验签结果：0未通过/未验签 1已通过',
  `received_at` timestamp NULL DEFAULT NULL COMMENT '收到回调时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '回调备注或处理说明',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人快照',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_callbacks_payment_type_unique` (`payment_id`,`callback_type`),
  KEY `payment_callbacks_verified_received_idx` (`is_verified`,`received_at`),
  KEY `payment_callbacks_gateway_trade_no_idx` (`gateway_trade_no`),
  KEY `payment_callbacks_trace_id_idx` (`trace_id`),
  KEY `payment_callbacks_plugin_received_idx` (`plugin_id`,`received_at`),
  KEY `payment_callbacks_gateway_key_idx` (`gateway_key`,`received_at`),
  CONSTRAINT `fk_payment_callbacks_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_callbacks_plugin_fk` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=476 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付回调审计表，保存第三方通知、查询、退款等回调验签结果';

CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '支付记录自增主键',
  `payment_no` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '内部支付单号',
  `user_id` bigint unsigned NOT NULL COMMENT '支付用户ID',
  `order_id` bigint unsigned DEFAULT NULL COMMENT '内部订单/开通投影ID，仅用于流程追踪',
  `invoice_id` bigint unsigned DEFAULT NULL COMMENT '关联账单ID',
  `plugin_id` bigint unsigned DEFAULT NULL,
  `gateway_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trade_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '第三方交易号',
  `amount` decimal(12,2) NOT NULL COMMENT '第三方支付金额',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '支付状态：0待支付 1成功 2失败 3已退款',
  `callback_raw` json DEFAULT NULL COMMENT '最近一次回调原始载荷 JSON',
  `paid_at` timestamp NULL DEFAULT NULL COMMENT '第三方确认支付时间',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '支付备注',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人快照',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_payment_no_unique` (`payment_no`),
  UNIQUE KEY `payments_plugin_trade_unique` (`plugin_id`,`gateway_key`,`trade_no`),
  KEY `payments_trade_no_index` (`trade_no`),
  KEY `payments_invoice_gateway_status_id_idx` (`invoice_id`,`status`,`id`),
  KEY `payments_invoice_status_created_at_idx` (`invoice_id`,`status`,`created_at`,`id`),
  KEY `payments_status_paid_at_idx` (`status`,`paid_at`),
  KEY `payments_user_status_created_idx` (`user_id`,`status`,`created_at`),
  KEY `payments_trace_id_idx` (`trace_id`),
  KEY `payments_order_status_idx` (`order_id`,`status`),
  KEY `payments_plugin_status_paid_idx` (`plugin_id`,`status`,`paid_at`),
  CONSTRAINT `fk_payments_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_payments_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_payments_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_plugin_fk` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=364 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方支付记录表，仅记录真实外部资金流入和退款状态，不记录余额/免费/手工开服';

CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_upstream_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `supplier_plugin_binding_id` bigint unsigned NOT NULL,
  `plugin_id` bigint unsigned NOT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `upstream_product_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `upstream_product_snapshot_json` json DEFAULT NULL,
  `option_schema_json` json DEFAULT NULL,
  `provision_policy_json` json DEFAULT NULL,
  `auto_setup` tinyint(1) NOT NULL DEFAULT '0',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_sync_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_upstream_unique` (`product_id`,`supplier_plugin_binding_id`,`upstream_product_id`),
  KEY `product_upstream_bindings_supplier_plugin_binding_id_foreign` (`supplier_plugin_binding_id`),
  KEY `product_upstream_product_status_idx` (`product_id`,`status`),
  KEY `product_upstream_provider_status_idx` (`provider_key`,`status`),
  KEY `product_upstream_plugin_status_idx` (`plugin_id`,`status`),
  KEY `product_upstream_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `product_upstream_bindings_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_upstream_bindings_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `product_upstream_bindings_supplier_plugin_binding_id_foreign` FOREIGN KEY (`supplier_plugin_binding_id`) REFERENCES `supplier_plugin_bindings` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=197 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '商品自增主键',
  `product_group_id` bigint unsigned DEFAULT NULL COMMENT '当前所属商品分组ID',
  `service_type_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '服务类型代码，用于前后端能力分流',
  `product_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '商品类型：vps/dedicated/hosting/domain/other',
  `custom_display_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '自定义展示名称',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '商品备注',
  `pricing` json NOT NULL COMMENT '周期价格 JSON，如 monthly/quarterly/annually',
  `setup_fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '初装费',
  `config_options` json DEFAULT NULL COMMENT '可选配置项 JSON',
  `purchase_requires` json DEFAULT NULL COMMENT '购买限制 JSON，如实名认证、手机号要求',
  `stock` int NOT NULL DEFAULT '-1' COMMENT '库存数量，-1 表示不限',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '商品状态：0下架 1上架',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序值，越小越靠前',
  `auto_setup` tinyint NOT NULL DEFAULT '0' COMMENT '是否自动开通：0手动 1自动',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `products_type_status_index` (`product_type`,`status`),
  KEY `idx_product_status_groups` (`status`),
  KEY `products_group_status_sort_id_idx` (`product_group_id`,`status`,`sort_order`,`id`),
  CONSTRAINT `products_product_group_fk` FOREIGN KEY (`product_group_id`) REFERENCES `third_product_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=379 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='商品表，记录可售卖产品的分类、定价、库存、上游绑定和开通策略';

CREATE TABLE `referral_account_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'reward_frozen|reward_released|withdraw_apply|withdraw_approved|withdraw_rejected',
  `change_amount` decimal(12,2) NOT NULL COMMENT '正=增加 负=减少',
  `frozen_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `available_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pending_withdrawal_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `withdrawn_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reference_id` bigint unsigned DEFAULT NULL,
  `reference_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_referral_account_user_type` (`user_id`,`event_type`),
  KEY `idx_referral_account_related` (`reference_type`,`reference_id`),
  KEY `referral_account_logs_created_at_index` (`created_at`),
  KEY `idx_referral_account_user_created_idx` (`user_id`,`created_at`,`id`),
  CONSTRAINT `fk_stage2_referral_account_logs_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referral_rewards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `referrer_user_id` bigint unsigned NOT NULL,
  `referred_user_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `product_id` bigint unsigned DEFAULT NULL,
  `order_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reward_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `reward_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `available_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=冻结中 1=已发放 2=已回退',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rewarded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_rewards_order_id_unique` (`order_id`),
  KEY `idx_referral_reward_referrer_status` (`referrer_user_id`,`status`),
  KEY `idx_referral_reward_referred_status` (`referred_user_id`,`status`),
  KEY `referral_rewards_product_id_index` (`product_id`),
  KEY `referral_rewards_rewarded_at_index` (`rewarded_at`),
  KEY `referral_rewards_invoice_id_idx` (`invoice_id`),
  CONSTRAINT `fk_stage2_referral_rewards_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_referral_rewards_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_referral_rewards_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_referral_rewards_referred_user_id` FOREIGN KEY (`referred_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_referral_rewards_referrer_user_id` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `referral_withdrawals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'alipay' COMMENT 'balance|alipay',
  `account_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `account_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=待处理 1=已通过 2=已拒绝',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referral_withdrawals_trace_id_unique` (`trace_id`),
  KEY `idx_referral_withdraw_user_status` (`user_id`,`status`),
  KEY `idx_referral_withdraw_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_stage2_referral_withdrawals_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permissions` json NOT NULL COMMENT '权限标识数组',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=396 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_run_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务名称',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT '执行状态: success, failed, skipped',
  `duration_ms` int unsigned NOT NULL DEFAULT '0' COMMENT '执行耗时(毫秒)',
  `summary` json DEFAULT NULL COMMENT '执行摘要数据',
  `error_msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `started_at` timestamp NULL DEFAULT NULL COMMENT '开始时间',
  `finished_at` timestamp NULL DEFAULT NULL COMMENT '结束时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `schedule_run_logs_task_name_index` (`task_name`),
  KEY `schedule_run_logs_status_index` (`status`),
  KEY `schedule_run_logs_created_at_index` (`created_at`),
  KEY `schedule_run_logs_task_name_created_at_index` (`task_name`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=148518 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_task_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_tick_id` bigint unsigned DEFAULT NULL,
  `task_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `task_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_description` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'heartbeat',
  `queue` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `duration_ms` int unsigned DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `error_msg` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `queued_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_task_runs_tick_task_source_unique` (`schedule_tick_id`,`task_key`,`source`),
  KEY `schedule_task_runs_task_key_created_at_index` (`task_key`,`created_at`),
  KEY `schedule_task_runs_status_created_at_index` (`status`,`created_at`),
  KEY `schedule_task_runs_source_created_at_index` (`source`,`created_at`),
  CONSTRAINT `schedule_task_runs_schedule_tick_id_foreign` FOREIGN KEY (`schedule_tick_id`) REFERENCES `schedule_ticks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=809 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_ticks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slot_started_at` timestamp NOT NULL,
  `global_number` bigint unsigned NOT NULL,
  `daily_index` tinyint unsigned NOT NULL,
  `triggered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_ticks_slot_started_at_unique` (`slot_started_at`),
  UNIQUE KEY `schedule_ticks_global_number_unique` (`global_number`),
  KEY `schedule_ticks_daily_index_index` (`daily_index`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `second_product_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `first_product_group_id` bigint unsigned NOT NULL COMMENT '→ first_product_groups.id',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL标识',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分组说明',
  `banner_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '横幅图',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序',
  `is_visible` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '前台可见',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_second_first_slug` (`first_product_group_id`,`slug`),
  KEY `idx_second_first_visible_sort` (`first_product_group_id`,`is_visible`,`sort_order`),
  CONSTRAINT `fk_second_first_group` FOREIGN KEY (`first_product_group_id`) REFERENCES `first_product_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_connection_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint unsigned NOT NULL,
  `service_upstream_binding_id` bigint unsigned DEFAULT NULL,
  `plugin_id` bigint unsigned DEFAULT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `connection_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port` int unsigned DEFAULT NULL,
  `connection_json` json DEFAULT NULL,
  `secret_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_secret_json` json DEFAULT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_connection_service_type_unique` (`service_id`,`connection_type`),
  KEY `service_connection_snapshots_service_upstream_binding_id_foreign` (`service_upstream_binding_id`),
  KEY `service_connection_plugin_checked_idx` (`plugin_id`,`checked_at`),
  KEY `service_connection_provider_type_idx` (`provider_key`,`connection_type`),
  KEY `service_connection_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `service_connection_snapshots_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_connection_snapshots_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_connection_snapshots_service_upstream_binding_id_foreign` FOREIGN KEY (`service_upstream_binding_id`) REFERENCES `service_upstream_bindings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=193 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_provision_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint unsigned DEFAULT NULL,
  `service_upstream_binding_id` bigint unsigned DEFAULT NULL,
  `plugin_id` bigint unsigned DEFAULT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_meta_json` json DEFAULT NULL,
  `response_meta_json` json DEFAULT NULL,
  `error_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempted_at` timestamp NULL DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_provision_attempts_service_upstream_binding_id_foreign` (`service_upstream_binding_id`),
  KEY `service_attempt_service_action_idx` (`service_id`,`action`,`attempted_at`),
  KEY `service_attempt_plugin_status_idx` (`plugin_id`,`attempt_status`,`attempted_at`),
  KEY `service_attempt_trace_idx` (`trace_id`),
  KEY `service_attempt_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `service_provision_attempts_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_provision_attempts_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_provision_attempts_service_upstream_binding_id_foreign` FOREIGN KEY (`service_upstream_binding_id`) REFERENCES `service_upstream_bindings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=420 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_runtime_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint unsigned NOT NULL,
  `service_upstream_binding_id` bigint unsigned DEFAULT NULL,
  `plugin_id` bigint unsigned DEFAULT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_text` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resource_json` json DEFAULT NULL,
  `metrics_json` json DEFAULT NULL,
  `snapshot_json` json DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_runtime_service_unique` (`service_id`),
  KEY `service_runtime_snapshots_service_upstream_binding_id_foreign` (`service_upstream_binding_id`),
  KEY `service_runtime_plugin_synced_idx` (`plugin_id`,`synced_at`),
  KEY `service_runtime_provider_status_idx` (`provider_key`,`status_key`),
  KEY `service_runtime_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `service_runtime_snapshots_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_runtime_snapshots_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_runtime_snapshots_service_upstream_binding_id_foreign` FOREIGN KEY (`service_upstream_binding_id`) REFERENCES `service_upstream_bindings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=193 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_upstream_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint unsigned NOT NULL,
  `product_upstream_binding_id` bigint unsigned DEFAULT NULL,
  `supplier_plugin_binding_id` bigint unsigned DEFAULT NULL,
  `plugin_id` bigint unsigned NOT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `upstream_service_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `upstream_account_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `runtime_snapshot_json` json DEFAULT NULL,
  `connection_snapshot_json` json DEFAULT NULL,
  `status_snapshot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_sync_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_upstream_unique` (`service_id`,`plugin_id`,`upstream_service_id`),
  KEY `service_upstream_bindings_product_upstream_binding_id_foreign` (`product_upstream_binding_id`),
  KEY `service_upstream_bindings_supplier_plugin_binding_id_foreign` (`supplier_plugin_binding_id`),
  KEY `service_upstream_service_idx` (`service_id`),
  KEY `service_upstream_provider_status_idx` (`provider_key`,`status_snapshot`),
  KEY `service_upstream_plugin_sync_idx` (`plugin_id`,`last_synced_at`),
  KEY `service_upstream_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `service_upstream_bindings_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_upstream_bindings_product_upstream_binding_id_foreign` FOREIGN KEY (`product_upstream_binding_id`) REFERENCES `product_upstream_bindings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_upstream_bindings_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_upstream_bindings_supplier_plugin_binding_id_foreign` FOREIGN KEY (`supplier_plugin_binding_id`) REFERENCES `supplier_plugin_bindings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=197 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT '服务实例自增主键',
  `user_id` bigint unsigned NOT NULL COMMENT '所属用户ID',
  `product_id` bigint unsigned NOT NULL COMMENT '关联商品ID',
  `order_id` bigint unsigned DEFAULT NULL COMMENT '内部订单/开通投影ID，仅用于流程追踪',
  `invoice_id` bigint unsigned DEFAULT NULL COMMENT '最近一次关联账单ID',
  `name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '服务自定义名称',
  `domain` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '服务域名或主机名',
  `billing_cycle` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '计费周期',
  `amount` decimal(12,2) NOT NULL COMMENT '服务续费/购买金额',
  `locked_pricing` json DEFAULT NULL COMMENT '锁定续费定价 JSON，null 表示跟随商品定价',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '服务状态：0待开通 1运行中 2已暂停 3已到期 4已取消',
  `provision_data` json DEFAULT NULL COMMENT '开通和上游实例数据 JSON',
  `expires_at` timestamp NULL DEFAULT NULL COMMENT '服务到期时间',
  `auto_renew` tinyint NOT NULL DEFAULT '1' COMMENT '是否自动续费：0关闭 1开启',
  `suspended_reason` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '暂停原因',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '服务备注',
  `operator` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作人快照',
  `trace_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '链路追踪号',
  PRIMARY KEY (`id`),
  KEY `services_user_id_index` (`user_id`),
  KEY `services_expires_at_index` (`expires_at`),
  KEY `services_user_status_id_idx` (`user_id`,`status`,`id`),
  KEY `services_status_expires_at_id_idx` (`status`,`expires_at`,`id`),
  KEY `services_trace_id_idx` (`trace_id`),
  KEY `services_product_id_idx` (`product_id`),
  KEY `services_order_id_idx` (`order_id`),
  KEY `services_invoice_id_idx` (`invoice_id`),
  CONSTRAINT `fk_services_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_services_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_services_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_stage2_services_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=300 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='服务实例表，记录用户已购买产品的生命周期、计费、上游和续费状态';

CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`),
  CONSTRAINT `fk_stage2_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_group_key_unique` (`group_key`,`item_key`)
) ENGINE=InnoDB AUTO_INCREMENT=343 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_plugin_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint unsigned NOT NULL,
  `plugin_id` bigint unsigned NOT NULL,
  `provider_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `priority` int NOT NULL DEFAULT '0',
  `base_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `config_json` json DEFAULT NULL,
  `secret_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_secret_json` json DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `last_check_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_check_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `backfill_batch_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_plugin_unique` (`supplier_id`,`plugin_id`,`environment`),
  KEY `supplier_plugin_provider_status_idx` (`provider_key`,`status`),
  KEY `supplier_plugin_plugin_status_idx` (`plugin_id`,`status`),
  KEY `supplier_plugin_backfill_batch_idx` (`backfill_batch_id`),
  CONSTRAINT `supplier_plugin_bindings_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `integration_plugins` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_plugin_bindings_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=84 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `suppliers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '0=停用 1=启用',
  `sort_order` int NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_code_unique` (`code`),
  KEY `suppliers_status_sort_order_index` (`status`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `third_product_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `second_product_group_id` bigint unsigned NOT NULL COMMENT '→ second_product_groups.id',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'URL标识',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '分组说明',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序',
  `is_visible` tinyint unsigned NOT NULL DEFAULT '1' COMMENT '前台可见',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_third_second_slug` (`second_product_group_id`,`slug`),
  KEY `idx_third_second_visible_sort` (`second_product_group_id`,`is_visible`,`sort_order`),
  CONSTRAINT `fk_third_second_group` FOREIGN KEY (`second_product_group_id`) REFERENCES `second_product_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ticket_replies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_staff` tinyint NOT NULL DEFAULT '0',
  `attachments` json DEFAULT NULL,
  `quote_reply_id` bigint unsigned DEFAULT NULL,
  `recalled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_replies_ticket_created_id_idx` (`ticket_id`,`created_at`,`id`),
  KEY `idx_stage2_ticket_replies_quote_reply_id` (`quote_reply_id`),
  CONSTRAINT `fk_stage2_ticket_replies_quote_reply_id` FOREIGN KEY (`quote_reply_id`) REFERENCES `ticket_replies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_replies_ticket_id` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tickets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `department` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'support',
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` tinyint NOT NULL DEFAULT '1' COMMENT '1=低 2=中 3=高 4=紧急',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '0=开启 1=客户回复 2=员工回复 3=已关闭',
  `service_id` bigint unsigned DEFAULT NULL,
  `assignee_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `close_reason` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'admin, client, auto',
  PRIMARY KEY (`id`),
  KEY `tickets_user_id_index` (`user_id`),
  KEY `tickets_user_status_updated_at_idx` (`user_id`,`status`,`updated_at`),
  KEY `tickets_status_updated_at_idx` (`status`,`updated_at`),
  KEY `tickets_user_updated_at_idx` (`user_id`,`updated_at`,`id`),
  KEY `tickets_service_id_idx` (`service_id`),
  KEY `idx_stage2_tickets_assignee_id` (`assignee_id`),
  CONSTRAINT `fk_stage2_tickets_assignee_id` FOREIGN KEY (`assignee_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_tickets_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tickets_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_accounts` (
  `user_id` bigint unsigned NOT NULL COMMENT '用户ID，同时作为账户主键',
  `cash_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '现金余额',
  `credit_limit` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '授信额度',
  `referral_frozen_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '冻结中的推荐奖励余额',
  `referral_available_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '可用推荐奖励余额',
  `referral_pending_withdrawal_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '提现审核中的推荐奖励余额',
  `referral_withdrawn_balance` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '已提现推荐奖励累计金额',
  `version` int unsigned NOT NULL DEFAULT '0' COMMENT '乐观锁版本号',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_accounts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户账户余额源表，集中承载现金余额、授信和推荐奖励余额';

CREATE TABLE `user_coupons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `coupon_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `receive_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'claim',
  `status` tinyint NOT NULL DEFAULT '1',
  `claimed_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `reserved_until` timestamp NULL DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trace_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_coupons_coupon_user_unique` (`coupon_id`,`user_id`),
  UNIQUE KEY `user_coupons_uid_unique` (`uid`),
  KEY `user_coupons_user_status_idx` (`user_id`,`status`),
  KEY `user_coupons_coupon_status_idx` (`coupon_id`,`status`),
  CONSTRAINT `fk_stage2_user_coupons_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_user_coupons_coupon_id` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=90 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '消息类型：order_paid/service_renew_reminder/service_expire_reminder 等',
  `title` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '点击跳转的前端路由',
  `data` json DEFAULT NULL COMMENT '附加业务数据',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `user_notifications_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `user_notifications_type_index` (`type`),
  CONSTRAINT `fk_stage2_user_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nickname` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `qq` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `alipay_real_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `alipay_account` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `referral_code` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referrer_user_id` bigint unsigned DEFAULT NULL,
  `member_level_id` bigint unsigned DEFAULT NULL,
  `total_sales_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `referred_at` timestamp NULL DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '0=禁用 1=正常',
  `login_email_alert` tinyint NOT NULL DEFAULT '1' COMMENT '登录邮件提醒 0关闭 1开启',
  `login_notify` tinyint(1) NOT NULL DEFAULT '1' COMMENT '账号登录提醒 0关闭 1开启',
  `login_location_alert` tinyint(1) NOT NULL DEFAULT '1' COMMENT '异地登录提醒 0关闭 1开启',
  `password_change_alert` tinyint(1) NOT NULL DEFAULT '1' COMMENT '密码变更提醒 0关闭 1开启',
  `phone_change_alert` tinyint(1) NOT NULL DEFAULT '1' COMMENT '手机号变更提醒 0关闭 1开启',
  `email_change_alert` tinyint(1) NOT NULL DEFAULT '1' COMMENT '邮箱变更提醒 0关闭 1开启',
  `marketing_alert` tinyint(1) NOT NULL DEFAULT '0' COMMENT '营销提醒接收 0关闭 1开启',
  `is_verified` tinyint NOT NULL DEFAULT '0' COMMENT '0=未认证 1=已认证',
  `real_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '真实姓名',
  `id_card` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `verification_status` tinyint NOT NULL DEFAULT '0' COMMENT '0=未认证 1=认证中 2=已认证 3=认证失败',
  `verification_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '实名认证状态描述',
  `verification_certify_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '实名认证平台 certify_id',
  `verified_at` timestamp NULL DEFAULT NULL COMMENT '实名认证通过时间',
  `last_login_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `admin_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_referral_code_unique` (`referral_code`),
  KEY `users_status_id_idx` (`status`,`id`),
  KEY `users_verification_mix_idx` (`is_verified`,`verification_status`,`id`),
  KEY `users_verification_status_id_idx` (`verification_status`,`id`),
  KEY `users_created_at_idx` (`created_at`),
  KEY `users_verification_certify_id_idx` (`verification_certify_id`),
  KEY `users_login_email_alert_index` (`login_email_alert`),
  KEY `users_referrer_user_id_index` (`referrer_user_id`),
  KEY `users_member_level_id_index` (`member_level_id`),
  CONSTRAINT `fk_stage2_users_member_level_id` FOREIGN KEY (`member_level_id`) REFERENCES `member_levels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stage2_users_referrer_user_id` FOREIGN KEY (`referrer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=988048 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `verification_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `real_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_card` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `verification_status` tinyint NOT NULL DEFAULT '1' COMMENT '1=认证中 2=已认证 3=认证失败',
  `verification_message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `verification_certify_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_biz_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FACE',
  `verification_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal',
  `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `verification_histories_user_id_submitted_at_index` (`user_id`,`submitted_at`),
  KEY `verification_histories_verification_certify_id_index` (`verification_certify_id`),
  KEY `verification_histories_user_id_id_idx` (`user_id`,`id`),
  CONSTRAINT `fk_stage2_verification_histories_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
