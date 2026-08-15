# 自动生成文档

此目录中的内容由代码或脚本生成。不得手工修改生成物；应修改源、运行生成命令，并提交生成结果。

| 生成物                                   | 生成命令                                             | 状态      | 用途                           |
| ---------------------------------------- | ---------------------------------------------------- | --------- | ------------------------------ |
| [DATABASE.md](../DATABASE.md)            | `php backend/scripts/export_database_structure.php`  | generated | 实库的结构基线。               |
| [接口/后端API清单.md](接口/后端API清单.md) | `php backend/scripts/export_api_inventory.php`       | generated | 路由、控制器、权限与接口清单。 |

`DATABASE.md` 位于顶层，便于作为各领域共用的数据库基线；它仍属于自动生成物，不得手工修改。
