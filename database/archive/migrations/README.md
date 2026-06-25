# Archived migrations (01–04)

These scripts upgraded **older** databases before structure was folded into `schema/easycount_schema.sql` (from Hostinger export) and `schema/banks_schema.sql`.

**Do not run on a fresh import** of `easycount_schema.sql` or `dumps/count168_site_*` — tables/columns/indexes already match; re-running causes duplicate-column/index errors.

## When you might still open these files

- Auditing when a column or trigger was introduced
- A legacy DB that never received a full dump and is missing specific objects

## Replacement workflow

| Need | Use instead |
|------|-------------|
| Full structure (empty DB) | `schema/easycount_schema.sql` |
| Full production data + routines | `dumps/` + [HOSTINGER_IMPORT.md](../../HOSTINGER_IMPORT.md) |
| Amount guard triggers only (local, no routines dump) | `schema/triggers_transactions_amount_guard.sql` |

Files: `01_owner_and_company.sql` … `04_indexes_and_maintenance.sql` (archived 2025-05).
