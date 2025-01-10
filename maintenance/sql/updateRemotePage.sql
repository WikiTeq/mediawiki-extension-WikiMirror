-- This file is intended to be run as part of the updateRemotePage.php or
-- LoadMainspacePages.php maintenance scripts.
-- It should *NOT* be run standalone!

DROP TABLE IF EXISTS /*_*/remote_page2;

CREATE TABLE /*_*/remote_page2 (
    -- Remote ID for this page
    rp_id int unsigned NOT NULL PRIMARY KEY,
    -- Remote namespace ID for this page
    rp_namespace int NOT NULL,
    -- DB key for the page (without namespace prefix)
    rp_title varbinary(255) NOT NULL,
    UNIQUE KEY rp_ns_title (rp_namespace, rp_title)
) /*$wgDBTableOptions*/;

INSERT INTO /*_*/remote_page2
SELECT
    page_id,
    page_namespace,
    page_title
FROM /*_*/wikimirror_page;

DROP TABLE /*_*/wikimirror_page;

RENAME TABLE
    /*_*/remote_page TO /*_*/remote_page_old,
    /*_*/remote_page2 TO /*_*/remote_page;

DROP TABLE /*_*/remote_page_old;
