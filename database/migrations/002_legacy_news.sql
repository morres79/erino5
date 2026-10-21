SET @legacy_key_migration = (
 SELECT IF(COUNT(*) = 0, 'ALTER TABLE news ADD COLUMN legacy_key CHAR(64) NULL UNIQUE', 'DO 0')
 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'news' AND column_name = 'legacy_key'
);
PREPARE legacy_news_statement FROM @legacy_key_migration;
EXECUTE legacy_news_statement;
DEALLOCATE PREPARE legacy_news_statement;
SET @legacy_order_migration = (
 SELECT IF(COUNT(*) = 0, 'ALTER TABLE news ADD COLUMN legacy_order INT NULL', 'DO 0')
 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'news' AND column_name = 'legacy_order'
);
PREPARE legacy_news_statement FROM @legacy_order_migration;
EXECUTE legacy_news_statement;
DEALLOCATE PREPARE legacy_news_statement;