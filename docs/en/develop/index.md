---
source: develop/index.md
sourceHash: a1204138a32a1d88f56587d997bbeca49b97e4a2c04f0c1fdce183591234fbfe
translatedAt: 2026-10-26
---

# Developer Documentation

For secondary development, upstream integration, and repository contributors: the structure of the running system, the engineering conventions of the three frontends, the database and API contracts, the plugin extension points, and local development and testing. If you only deploy and operate this system, go to the [User Documentation](../guide/index.md).

## 1. Enter by Purpose

| Purpose                                                    | Entry Point                                                                                                               |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Understand how the system runs first                       | [System Architecture](architecture.md)                                                                                    |
| Change the backend / frontend / visual design              | [Backend Guidelines](backend.md), [Frontend Guidelines](frontend.md), [Visual and Interaction Guidelines](design.md)      |
| Look up table structures and SQL compatibility constraints | [Database Snapshot](/develop/database), [MySQL Version Compatibility Baseline](mysql-compatibility.md)                    |
| Integrate with or refactor APIs                            | [API Documentation](api/index.md), [Backend API Catalog](/develop/api/backend-api-catalog)                                |
| Write plugins (provider / payment / SMS)                   | [Plugin Development](plugins/index.md), [Plugin Package Specification](plugins/package-spec.md)                           |
| Interconnect with upstream systems                         | [ZJMF Upstream Protocol](integrations/zjmf-upstream-api.md), [Local Integration Guide](integrations/local-integration.md) |
| Run the system locally and run tests                       | [Local Development](local-development.md), [Testing Guide](testing.md)                                                    |
| Before submitting changes                                  | [Secure Development Guidelines](security.md), [Engineering Pitfalls](pitfalls.md), [Contributing Guide](contributing.md)  |

## 2. Fact Priority and Generated Artifacts

On conflict, the order of precedence is: the running code and tests, the real database `information_schema`, the auto-generated documents, then the conventions in this directory. The two auto-generated artifacts **must not be edited by hand**; re-run the scripts instead:

```bash
php backend/scripts/export_database_structure.php   # 刷新数据库快照 develop/database.md
php backend/scripts/export_api_inventory.php        # 刷新后端 API 清单
```

## 3. Required Reading Before Changes

- [Backend Guidelines](backend.md) and [Frontend Guidelines](frontend.md): keep controllers thin, put third-party calls in Services/Drivers/plugins, and do not mix UI frameworks across the three frontends.
- [Secure Development Guidelines](security.md): the WAF is only an outer layer of defense, so authentication and privilege escalation issues must be fixed at the authentication and authorization layer; a signature or token key that reads as empty must refuse service.
- [MySQL Version Compatibility Baseline](mysql-compatibility.md): SQL must be compatible with both MySQL 5.7.44 and 8.x, and MySQL 8.0-only syntax such as window functions and CTEs is forbidden.
- [Engineering Pitfalls](pitfalls.md): measured pitfalls in the steps of changing code, editing copy, running tests, committing, and incremental deployment.

## Related Documentation

- [User Documentation](../guide/index.md): Deployment, configuration, and daily operations manual.
