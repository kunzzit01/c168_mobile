# Hostinger 导入 count168 数据

应用连接的数据库：`u857194726_count168_site`（见 `includes/config.php`）。

## 目录说明

| 路径 | 用途 |
|------|------|
| `dumps/` | 可提交/上传的成品 SQL（表数据 + routines） |
| `scripts/` | 从 Hostinger 原始 dump 生成 `dumps/` 的 PowerShell |
| `generated/` | 中间文件（不提交 git，可重新生成） |
| `archive/migrations/` | 已归档的旧增量脚本（新库勿用） |
| `schema/triggers_transactions_amount_guard.sql` | 仅本地空库且未导入 routines 时可选 |
| `ops/` | 一次性运维脚本（需改参数再执行） |

## 正确做法（两步）

### 第 1 步：phpMyAdmin 导入表和数据

文件：**`dumps/count168_site_phpmyadmin_tables_data.sql`**

1. 登录 Hostinger → phpMyAdmin → 选中 **`u857194726_count168_site`**
2. **必须**先清空：左侧 **全选表/视图 → 删除（Drop）**（半截导入再跑会 #1050）
3. **导入** → 选择上述 SQL（过大可用 `dumps/count168_site_phpmyadmin_tables_data.zip`）
4. 等到完成（约 70MB，可能需几分钟）

### 第 2 步：导入存储过程 / 触发器 / 事件

文件：**`dumps/count168_site_routines_mysql.sql`**

phpMyAdmin **不能**可靠导入带 `DELIMITER` 的 routines，请用下面任一方式：

**A. Hostinger SSH / 终端（推荐）**

```bash
mysql -u u857194726_count168_site -p u857194726_count168_site < dumps/count168_site_routines_mysql.sql
```

**B. 本机 XAMPP（若库在本地）**

```powershell
cd database
C:\xampp\mysql\bin\mysql.exe -u root u857194726_count168_site -e "source C:/path/to/database/dumps/count168_site_routines_mysql.sql"
```

**C. 仅第 1 步、跳过第 2 步**

网站大部分功能可用；账户货币 procedure、部分 backup 触发器可能不可用，建议尽量完成第 2 步。

### 若第 1 步前 procedure 已存在

在 phpMyAdmin **SQL** 标签执行：`dumps/count168_site_drop_routines.sql`

---

## 重新生成 `dumps/` 下的拆分文件

从 Hostinger 下载的**原始** `.sql` 开始：

```powershell
cd database\scripts
.\import_count168_site_dump.ps1 -InputPath "C:\path\to\hostinger_export.sql" -SkipImport
```

会写入 `generated/count168_site_import_prepared.sql`，并更新 `dumps/count168_site_phpmyadmin_tables_data.sql` 与 `dumps/count168_site_routines_mysql.sql`。

---

## 已移除的文件（勿再找）

- `count168_site_import_phpmyadmin.sql` — phpMyAdmin 会 #1064，已删除
- `prepare_count168_site_for_phpmyadmin.ps1` — 仅生成上述不可用文件
- `count.sql` / 根目录旧 dump — 已由 `dumps/count168_site_*` 取代
- 旧版分散的 `add_*.sql` / `migrations/01`–`04` — 已归档到 `archive/migrations/`（结构已含于 `easycount_schema` 与 dumps）

旧库全量备份见：`archive/legacy_dumps/count_fixed.sql`（`u857194726_count168`，仅作归档参考）。
