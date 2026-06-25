# database/

SQL、导入工具与结构定义。不影响运行时 PHP/API；仅用于建库与运维。

## 快速索引

| 场景 | 看什么 |
|------|--------|
| 生产 / Hostinger 全量导入 | [HOSTINGER_IMPORT.md](HOSTINGER_IMPORT.md) |
| 本地空库结构（无业务数据） | **`schema/easycount_schema.sql`** |
| 本地空库 + 交易金额触发器（无 routines dump） | `schema/triggers_transactions_amount_guard.sql` |
| 历史增量脚本（勿对新库执行） | `archive/migrations/` |
| 一次性数据修复 | `ops/`（执行前阅读文件内参数） |

## 目录结构

```
database/
  README.md
  HOSTINGER_IMPORT.md
  dumps/                    <- 成品全量（表+数据 / routines），可选
  scripts/                  <- 合并 schema、生成 dumps 的 PowerShell
  generated/                <- 中间 SQL（git 忽略）
  schema/
    easycount_schema.sql    <- 空库 DDL（games + banks 合并）
    games_schema.sql
    banks_schema.sql
    triggers_transactions_amount_guard.sql
  migrations/               <- 仅 README；脚本在 archive/migrations/
  archive/
    migrations/             <- 已废弃的 01-04 增量脚本
    legacy_dumps/
  ops/
```

## 本地空库（推荐顺序）

```powershell
cd database\scripts
.\merge_easycount_schema.ps1
mysql -u root < ..\schema\easycount_schema.sql
mysql -u root easycount < ..\schema\triggers_transactions_amount_guard.sql
```

若使用 Hostinger 全量 `dumps/`，第 2 步 routines 通常已含触发器，可跳过 `triggers_transactions_amount_guard.sql`。

修改 `games_schema.sql` 或 `banks_schema.sql` 后请重新运行 `merge_easycount_schema.ps1`。

合并规则：共有表以 **games** 为准；仅 banks 有的表（如 `transactions`、`bank_process`）从 **banks** 追加。

## 与 `includes/config.php` 的关系

应用使用 `u857194726_count168_site`。导入 `dumps/` 后无需改代码；仅需保证 `includes/config.php` 中 host/user/password 与目标 MySQL 一致。
