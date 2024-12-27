# WikiMirror

This extension allows for mirroring and forking pages from a remote wiki.
At this time, a number of configuration considerations must be made for this to work:

- Namespaces must match exactly between the local and remote wikis.
- The remote wiki API must be accessible.

Further documentation will be written as this extension gets closer to release.

**Imports are the MySQL dumps are not compatible with SQLite**

## Updating

Assuming that your remote wiki is the English wikipedia
* download the latest `page` table dump
* run `php maintenance/run WikiMirror:updateRemotePage --page extensions/WikiMirror/enwiki-20241201-page.sql.gz --out extensions/WikiMirror/pages.sql`
    where the value for `--page` is the path to the dump
* run `php maintenance/run sql extensions/WikiMirror/pages.sql` to load the script

* run `php maintenance/run WikiMirror:updateRemotePage --finish`
