-- mailhub_collect: where each collected address was found.
CREATE TABLE `mailhub_collect_origin` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(191) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `folder` varchar(191) NOT NULL DEFAULT '',
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  PRIMARY KEY (`user_id`, `email`, `kind`, `folder`),
  CONSTRAINT `fk_mailhub_collect_user_id` FOREIGN KEY (`user_id`)
    REFERENCES `users`(`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT INTO `system` (`name`, `value`) VALUES ('mailhub_collect-version', '2026111000');
