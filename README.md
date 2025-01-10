# WikiMirror

This extension allows for mirroring and forking pages from a remote wiki.
At this time, a number of configuration considerations must be made for this to work:

- Namespaces must match exactly between the local and remote wikis.
- The remote wiki API must be accessible.

Further documentation will be written as this extension gets closer to release.

**Imports are the MySQL dumps are not compatible with SQLite**

## Updating

The following instructions assume that your remote wiki is the English Wikipedia.

### Mirroring just NS_MAIN

If only the main namespace (NS_MAIN) is mirrored, rather than needing to
download the dump of the entire `page` table, the dump with the list of titles
present on the wiki in the main namespace is enough, and will be much faster to
downloaded and import. Assuming that the dump is available at
`extensions/WikiMirror/enwiki-20241201-all-titles-in-ns0.gz` (for example), run
* `php maintenance/run WikiMirror:LoadMainspacePages --page extensions/WikiMirror/enwiki-20241201-all-titles-in-ns0.gz --out extensions/WikiMirror/pages.sql`
    where the value for `--page` is the path to the dump
* run `php maintenance/run sql extensions/WikiMirror/pages.sql` to load the
    pages into the `wikimirror_page` table
* run `php maintenance/run WikiMirror:LoadMainspacePages --finish` to move the
    pages into the `remote_page` table

Note that when imported this way, the page ids that are stored in the
`remote_page.rp_id` field will not actually match the page ids from the remote
wiki, but will be auto-incremented values provided by the maintenance script.

### Mirroring other namespaces
* download the latest `page` table dump
* run `php maintenance/run WikiMirror:updateRemotePage --page extensions/WikiMirror/enwiki-20241201-page.sql.gz --out extensions/WikiMirror/pages.sql`
    where the value for `--page` is the path to the dump
* run `php maintenance/run sql extensions/WikiMirror/pages.sql` to load the script

* run `php maintenance/run WikiMirror:updateRemotePage --finish`
