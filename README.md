# report-composer-diff

This Symfony Console command compares composer.lock files between two Git references (tags, branches, or commits) and generates a detailed report of package changes.

Key features:

- Supports specifying source (--from) and target (--to) Git tags; if not provided, falls back to the two latest tags.
- Reads composer.lock directly from Git without checking out the refs.
- Detects changes, even if only branches are used (Branch names in composer.json must start with `dev-`, for example `dev-develop`)
- Classifies packages into added, removed, updated, and unchanged.
- Supports custom groups based on package name prefixes, in addition to built-in TYPO3 groups. Multiple prefixes per group are allowed.
- Outputs results in multiple formats: console, HTML, JSON, Markdown, or plain text.
- Generates a summary table per group and a detailed per-package report.

# Installation
I recommend installing in dev environment

`composer require traw/report-composer-diff --dev`

# Usage

```
--html      - Write report.html
--json      - Write report.json
--txt       - Write report.txt
--md        - Write report.md
--filename  - Filename (& directory) where the report should be saved (needs --html, --md, --txt or --json)
--from      - Begin at git-ref
--to        - Stop at git-ref
--repo      - change directory
--group     - add one or more custom groups in the format groupname:prefix/,prefix2/,prefix3
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

---

Custom group

`php vendor/bin/composer-diff --group=mmygroup:traw/ --group=mycompany:namespaceprefix/`

---

Multiple prefixes in one group - groupname:comma-list

`php vendor/bin/composer-diff --group=mycompany:prefix1/,prefix2/,prefix3`
