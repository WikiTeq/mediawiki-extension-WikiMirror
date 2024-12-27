-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/WikiMirror/schema/json/forked_titles.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/forked_titles (
  ft_namespace INT NOT NULL,
  ft_title VARBINARY(255) NOT NULL,
  ft_remote_page INT UNSIGNED DEFAULT NULL,
  ft_remote_revision INT UNSIGNED DEFAULT NULL,
  ft_forked VARBINARY(14) NOT NULL,
  ft_imported TINYINT DEFAULT 0 NOT NULL,
  ft_token VARBINARY(255) DEFAULT NULL,
  PRIMARY KEY(ft_namespace, ft_title)
) /*$wgDBTableOptions*/;