# GEDCOM 7 conversion regression tests

Install the module's Composer dependencies and obtain a webtrees release with
its dependencies. Run with PHP 8.1 or later:

```sh
php tests/gedcom7.php /path/to/webtrees
```

An optional second argument selects a different module checkout. This allows
the same suite to demonstrate failures against an unpatched version:

```sh
php tests/gedcom7.php /path/to/webtrees /path/to/unpatched/module
```

The suite uses real `importGedcomFileToRecords()` and `filteredExport()` calls,
including stream encoding and filter application. It does not need a running
web application, database, network connection or PHPUnit installation. Failures
produce a nonzero exit status; assertions do not depend on `php.ini` settings.

Coverage includes identifier collisions and forward references, unchanged text
and media paths, missing targets, unchanged GEDCOM 5 identifiers, complete MIME
value matching, person and event associations in both ASSO forms, relationship
phrases, repeat conversion, extension retention, and merging existing schemas.

These checks test conversion, not the completeness of a downstream importer.
