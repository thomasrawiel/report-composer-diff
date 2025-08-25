# report-composer-diff

Create a report of changes in the composer.lock file based on the given git-refs

# Usage

```
--html      - Write report.html
--json      - Write report.json
--txt       - Write report.txt
--md        - Write report.md
--filename  - Filename (& directory) where the report should be saved (needs --html or --json)
--from      - Begin at git-ref
--to        - Stop at git-ref
--repo      - change directory
--group     - add one or more custom groups in the format groupname:prefix/
```


CLI table output

`bin/php vendor/bin/composer-diff`

---

Writes report.html

`php vendor/bin/composer-diff --html`

---

Writes report.json

`php vendor/bin/composer-diff --json`

---

Compare Tags

`php vendor/bin/composer-diff --from=v12.4.2 --to=v12.4.3 --html` 

---

Compare Tag to current

`php vendor/bin/composer-diff --from=1.0.0 --html`

---

Compare Branch to Branch

`php vendor/bin/composer-diff --from=develop --to=main --html`

---

Write to a subdirectory

`php vendor/bin/composer-diff --html --filename=report/report.html`

Custom group

`php vendor/bin/composer-diff --group=mmygroup:traw/ --group=mycompany:namespaceprefix/` 