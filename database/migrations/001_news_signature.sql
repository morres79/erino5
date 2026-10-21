-- Repeatable, additive migration. Existing news content is preserved.
SET @news_signature_migration = (
 SELECT IF(COUNT(*) = 0,
   'ALTER TABLE news ADD COLUMN signature VARCHAR(255) NOT NULL DEFAULT '''' AFTER body',
   'DO 0')
 FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'news' AND column_name = 'signature'
);
PREPARE news_signature_statement FROM @news_signature_migration;
EXECUTE news_signature_statement;
DEALLOCATE PREPARE news_signature_statement;