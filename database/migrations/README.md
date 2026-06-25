# migrations/

增量脚本已归档到 **`archive/migrations/`**（`01`–`04`）。

当前建库方式：

1. **生产 / 带数据**：`dumps/` + [HOSTINGER_IMPORT.md](../HOSTINGER_IMPORT.md)
2. **本地空库结构**：`schema/easycount_schema.sql`（由 `games_schema.sql` + `banks_schema.sql` 合并生成）
3. **仅缺交易金额触发器**（未导入 routines dump 时）：`schema/triggers_transactions_amount_guard.sql`

请勿在新环境上执行 `archive/migrations/` 里的脚本；内容与 `easycount_schema` 重复，且可能报错。
