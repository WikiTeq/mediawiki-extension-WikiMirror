-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/WikiMirror/schema/json/remote_redirect.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/remote_redirect (
  rr_from INT UNSIGNED NOT NULL,
  rr_namespace INT NOT NULL,
  rr_title VARBINARY(255) NOT NULL,
  rr_updated BINARY(14) NOT NULL,
  INDEX rr_ns_title (rr_namespace, rr_title),
  PRIMARY KEY(rr_from)
) /*$wgDBTableOptions*/;
