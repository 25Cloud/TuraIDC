---
source: guide/migrate/index.md
sourceHash: d4a94c48ab7662af3375ca8d025f6819e92bdee8820ef5e0734c5c93dd4d00f1
translatedAt: 2026-10-26
---

# Data Migration

Move existing business data into TuraIDC: migrate the existing customers, products, orders, and upstream bindings from the ZJMF Finance system, or map heterogeneous data from a legacy IDC system across.

## 1. Migration Paths

| Source                                                             | When it applies                                                                                                                   |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| [Migrating from ZJMF](zjmf-finance.md)                             | The old site runs ZJMF, and users, products, orders, upstream bindings, and real-name verification must all move over as a whole  |
| [ZJMF migration (experimental tool)](zjmf-finance-experimental.md) | Experimental migration tool for `shd_`-prefixed data; the official guide has superseded it and it is kept for traceability only   |
| [Local IDC data migration](local-idc.md)                           | The legacy IDC system's table structure differs from TuraIDC, so heterogeneous field mapping and a controlled import are required |

## 2. Mandatory Steps Before Migration

1. **Back up the source and target databases in full**, and confirm the backups can be restored — every failed step during migration falls back to them.
2. **Run the whole flow once in a non-production environment**, including structural and data acceptance after the import; never try it out on the production database.
3. **Record the mapping decisions**: which fields are aligned, which are dropped, and which need a human call on ownership — write them into a table and keep it on record.
4. **Schedule a maintenance window**: do not let users order or pay during the migration, so old and new data never write across each other.
5. **Verify item by item after migration**: users can sign in, balances reconcile with order amounts, instances can be renewed, and upstream bindings work.

## 3. Post-Migration Review

- User and order counts match between the source and target sites, or any difference has a clear explanation.
- Balances, invoices, and accounting entries reconcile; investigate mismatches per [Finance and Statements](../operate/finance.md).
- Sample at least 3 real instances to verify that renewal and upstream operations work.
- Keep the source site read-only for a period after migration completes, and take it offline only once nothing has been missed.

## Related Documentation

- [Backup and Restore](../maintain/backup.md): backup and restore procedures before and after migration.
- [Initial Configuration](../configure/index.md): finish site and channel configuration after the migration completes.
