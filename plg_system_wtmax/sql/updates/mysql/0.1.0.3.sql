CREATE TABLE IF NOT EXISTS `#__plg_system_wtmax_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `message_id` varchar(191) NOT NULL COMMENT 'MAX message id',
  `chat_id` bigint DEFAULT NULL COMMENT 'MAX chat id',
  `context` varchar(150) DEFAULT NULL COMMENT 'Joomla context like com_content.article',
  `item_id` int unsigned DEFAULT NULL COMMENT 'Joomla item id',
  `attachment_count` int unsigned NOT NULL DEFAULT 0 COMMENT 'Number of sent attachments',
  `date` bigint unsigned NOT NULL COMMENT 'MAX message timestamp in milliseconds',
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_context_item` (`context`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
