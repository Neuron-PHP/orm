## 0.1.15 2026-10-08

* Added whereRaw and orWhereRaw for predicates with no fluent equivalent.
* Added parenthesised clause groups via where( callback ) and orWhere( callback ),
  so `a AND ( b OR c )` is now expressible.
* Added cursor() and cursorRaw() to stream large result sets without building an array.
* Added whereNotIn, orWhereIn, orWhereNotNull and whereColumn.
* Added having() and havingRaw().
* Fixed: offset() without limit() emitted `LIMIT -1`, which Postgres and MySQL reject.
  The placeholder is now only emitted for SQLite, which needs it.
* Fixed: where( $column, null ) bound NULL to `=` and so matched nothing; a null value
  now compiles to IS NULL, and `!=` / `<>` to IS NOT NULL.
* Fixed: bindings are bound with their PHP types rather than all as strings, so a
  comparison against an expression with no column affinity (an aggregate in a HAVING
  clause) no longer silently fails.
* Fixed: count() and the aggregate methods ignored the table alias and JOINs.

## 0.1.14 2026-10-04

## 0.1.13 2026-09-11
* Relocated the migration commands to this package.

## 0.1.12 2026-09-10

## 0.1.11 2026-01-13
## 0.1.10 2026-01-13
## 0.1.9 2026-01-13

## 0.1.8 2025-12-22
* Added attach, detach and sync methods for many-to-many relationships.
* Added raw results support.

## 0.1.7 2025-12-22
* Added group by support.

## 0.1.6 2025-12-22
* Added join support.
* Added column selection.

## 0.1.5 2025-12-19
## 0.1.4 2025-12-19
* Added increment and decrement methods to the ORM model.

## 0.1.3 2025-12-19
* Added transaction support.

## 0.1.2 2025-12-02
## 0.1.1 2025-11-28

* Added dependency destroy capability.

## 0.1.0 2025-11-11

* Initial release
