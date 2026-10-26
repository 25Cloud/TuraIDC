---
source: develop/contributing.md
sourceHash: ee9a52c508f4bb7e42faef0146a3256a229eef54b6624b4cc8c3598abdb3f444
translatedAt: 2026-10-26
---

# Contributing

For contributors who submit code, documentation changes, or issue reports to TuraIDC. This page covers only what you must know before submitting anything; `AGENTS.md` at the repository root is the navigation map for coding agents, and the full set of constraints is defined by the working rules in the repository (see the links at the end).

## 1. Before You Start

1. Read the documentation and the existing code related to the task first; do not guess interfaces, business logic, or directory structure.
2. Keep changes minimal and do not perform unrelated refactoring; do not keep compatibility layers for dead code that has no callers.
3. The workspace may be dirty: never revert, overwrite, or reformat changes that this task did not make.
4. No secret key, password, token, private key, or production data may enter code, documentation, commit messages, or logs.

## 2. Where Changes Belong

| Change type                           | Location                                                                                                                  |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Backend business logic and APIs       | `backend/app/`; controllers stay a thin layer, and third-party calls go into a dedicated Service, Driver, or plugin       |
| Plugins                               | `backend/plugins/{domain}/{slug}/`; plugin-specific logic does not pollute system-level extension points                  |
| The three frontends                   | `frontend-admin-v3/`, `frontend-user-v3-www/`, `frontend-user-v4-console/`; cross-frontend capabilities go into `shared/` |
| User documentation                    | `docs/guide/`                                                                                                             |
| Developer documentation               | `docs/develop/`                                                                                                           |
| Execution plans, designs, fix records | `docs/internal/`, and register them in `docs/catalog.json`                                                                |

## 3. Verification Before Submitting

| Scope         | Minimum verification                                                                                            |
| ------------- | --------------------------------------------------------------------------------------------------------------- |
| Documentation | `pnpm run docs:check`                                                                                           |
| `backend/`    | `cd backend && php artisan test` (you may narrow it to the affected files first)                                |
| Each frontend | `pnpm run build` in the corresponding directory; for refactoring, additionally run that app's `verify:refactor` |
| `shared/`     | `pnpm run typecheck:shared && pnpm run test:shared`, then build the affected frontends                          |

Frontend UI changes must add the corresponding e2e cases as the repository requires. If you cannot run verification, state the reason and the resulting uncovered risk in your description.

## 4. CLA Signing and License Grant

This project is **dual-licensed under AGPL-3.0-or-later and a commercial license**. So that the project side can distribute your contribution under both licenses, **you must sign the [Contributor License Agreement (CLA)](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md) before your first PR**:

- **Individual contributors**: reply in any PR comment with `I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md）`; it takes effect once a maintainer records it. You may also sign by sending an email to `contact@turaidc.com`.
- **Organizational contributions** (submitted by employees or contractors within the scope of their duties): an authorized representative of the organization signs by sending an email to `contact@turaidc.com` as described in [Part 2 of CLA.md](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md), attaching a list of the GitHub usernames of the designated contributors.
- **Effective date and historical contributions**: the CLA takes effect on **2026-10-21** and applies to new contributions from then on. Contributions already submitted before the effective date remain licensed under AGPL by default and are not covered retroactively; historical contributors may voluntarily sign retroactively by adding "and applies to all contributions I submitted before 2026-10-21" to their signing statement, and third-party historical contributions that are not covered retroactively do not enter the commercial version. See the [explanation at the beginning of the CLA](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md#生效日期历史贡献与可行性结论) for details.
- Signing once covers all contributions you submit to this project afterwards, so you do not need to sign again for every PR; PRs without a signature are reminded during review, and contributions for which a relicensing grant cannot be obtained may not be merged.
- Once a contribution is merged, it is made available to the public under AGPL-3.0-or-later at the same time and the project side is authorized to redistribute it under the commercial license; **the public rights already granted under AGPL are not reduced**, and you remain the copyright holder of your own contribution.
- If you do not agree to the grant above, please do not submit contributions; you may still use this project freely under AGPL. See [Code of Conduct](https://github.com/25Cloud/TuraIDC/blob/main/CODE_OF_CONDUCT.md) for the community behavior rules.

Do not submit security vulnerabilities through a public issue or PR; report them privately as described in the [Security Policy](https://github.com/25Cloud/TuraIDC/blob/main/SECURITY.md).

## 5. Commits and Collaboration

- One commit does one thing, and the message is written in Chinese: `Fix:中文描述`, `Feat:中文描述`, `Refactor:中文描述`; the repository hook requires exactly one space after the colon.
- Changes are merged into `main` through a Pull Request; describe the change scope, the verification commands, and their results in the description.
- Changes involving routes, database structure, deployment method, API contracts, or user flows must review and update the corresponding documentation at the same time; if no documentation change is truly needed, explain the reason in the PR.
- Generated artifacts (the database snapshot and the backend API catalog) may only be regenerated by script; manual editing is prohibited.

## Related Documentation

- [Working Rules](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md): the complete mandatory rules for collaboration, changes, verification, and commits.
- [Documentation Policy](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/DOCUMENTATION_POLICY.md): documentation placement, structure, and lifecycle.
- [Engineering Pitfalls](pitfalls.md): pitfalls actually hit during commits, testing, and incremental deployment.
- [Testing Guide](testing.md): test environments, commands, and scope.
- [Open Source and Commercial Licensing](../guide/licensing.md): mandatory AGPL obligations, when a commercial license applies, and how to obtain one.
- [Contributor License Agreement (CLA)](https://github.com/25Cloud/TuraIDC/blob/main/CLA.md), [Commercial License Draft](https://github.com/25Cloud/TuraIDC/blob/main/LICENSE-COMMERCIAL.md).
