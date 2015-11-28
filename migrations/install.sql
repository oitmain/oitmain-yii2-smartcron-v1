CREATE TABLE `cron` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `created_at` datetime NOT NULL,
 `updated_at` datetime NOT NULL,
 `name` varchar(100) NOT NULL,
 `scheduled_at` datetime NOT NULL,
 `expression` varchar(30) NOT NULL,
 `start_mt` decimal(13,3) DEFAULT NULL,
 `end_mt` decimal(13,3) DEFAULT NULL,
 `heartbeat_mt` decimal(13,3) DEFAULT NULL,
 `status` enum('DEAD','TIMEOUT','ERROR','SUCCESS','PAUSED','RUNNING','SCHEDULED','MISSED') NOT NULL DEFAULT 'SCHEDULED',
 `paused_mt` decimal(13,3) DEFAULT NULL,
 `elapsed` decimal(13,3) DEFAULT NULL,
 `cleanup` tinyint(1) NOT NULL DEFAULT '1',
 PRIMARY KEY (`id`),
 UNIQUE KEY `name_2` (`name`,`scheduled_at`),
 KEY `created_at` (`created_at`),
 KEY `updated_at` (`updated_at`),
 KEY `name` (`name`),
 KEY `scheduled_at` (`scheduled_at`),
 KEY `heatbeat_mt` (`heartbeat_mt`),
 KEY `status` (`status`),
 KEY `paused_mt` (`paused_mt`),
 KEY `elapsed` (`elapsed`),
 KEY `start_mt` (`start_mt`),
 KEY `end_mt` (`end_mt`)
) ENGINE=InnoDB AUTO_INCREMENT=16212 DEFAULT CHARSET=latin1


CREATE TABLE `cron_detail` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `cron_id` int(11) NOT NULL,
 `start_mt` decimal(13,3) NOT NULL,
 `end_mt` decimal(13,3) DEFAULT NULL,
 `status` enum('RUNNING','DEAD','TIMEOUT','ERROR','SUCCESS','PAUSED') NOT NULL DEFAULT 'RUNNING',
 `elapsed` decimal(13,3) DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `cron_id` (`cron_id`),
 KEY `end_mt` (`end_mt`),
 KEY `status` (`status`),
 KEY `elapsed` (`elapsed`),
 KEY `start_mt` (`start_mt`),
 CONSTRAINT `cron_detail_ibfk_1` FOREIGN KEY (`cron_id`) REFERENCES `cron` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1