SET @important_news_migration = (
 SELECT IF(COUNT(*) = 0, 'ALTER TABLE news ADD COLUMN is_important TINYINT NOT NULL DEFAULT 0', 'DO 0')
 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'news' AND column_name = 'is_important'
);
PREPARE important_news_statement FROM @important_news_migration;
EXECUTE important_news_statement;
DEALLOCATE PREPARE important_news_statement;
