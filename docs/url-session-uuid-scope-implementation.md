# URL 隐藏数据库 ID 实现指南（方案 1 + 方案 2 UUID）

本文说明如何在 EazyCount / Count168 项目中，用 **方案 1（Session 状态）** 和 **方案 2（UUID 公开标识）** 替换 URL 中直接暴露的 `company_id=5`、`account_db_id=4837` 等参数。

---

## 1. 目标与原则

### 1.1 要达成的效果

| 场景 | 现在 | 改完后 |
|------|------|--------|
| 站内切换公司后浏览 Account List | `/account-list?company_id=5` | `/account-list` |
| 站内浏览 Dashboard | 可能带 `company_id` | `/dashboard` |
| 新标签打开 Payment History（可分享） | `/transaction?company_id=5&account_db_id=4837&...` | `/transaction/payment-history/{uuid-token}` 或 `/transaction?ctx={short-uuid}` |
| API 请求（浏览器 Network 面板） | 仍可带内部 ID | **可以**继续用内部 ID（不暴露在地址栏即可） |

### 1.2 安全原则（必读）

- **隐藏 URL ≠ 加密安全**。UUID 主要防止猜测和枚举，不能替代权限校验。
- 后端每个 API 在解析 `company_id` / `account_id` 后，**必须继续执行现有权限检查**（如 `gc_session_can_access_company_id`、`user_company_map` 等，见 `api/transactions/transaction_scope.php`）。
- 全站 HTTPS、Session Cookie `HttpOnly` + `Secure` + `SameSite` 是基础要求。

### 1.3 两种方案分工

```
┌─────────────────────────────────────────────────────────────┐
│  用户在已登录 SPA 内点击导航（Account / Dashboard / …）      │
│  → 方案 1：不写 company_id 到地址栏                         │
│  → 从 sessionStorage + PHP Session 读取当前公司上下文         │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  需要书签 / 分享 / 新标签打开的链接（Payment History 等）     │
│  → 方案 2：URL 只放 UUID（public_id），不放数字主键           │
│  → 后端 UUID → 内部 id，再验权                               │
└─────────────────────────────────────────────────────────────┘
```

---

## 2. 数据库：新增 `public_id`（UUID）

### 2.1 需要加 UUID 的表

至少建议：

| 表 | 内部主键 | 新增列 | 用途 |
|----|----------|--------|------|
| `company` | `id` (int) | `public_id` CHAR(36) UNIQUE | 公司 scope |
| `account` | `id` (int) | `public_id` CHAR(36) UNIQUE | Payment History 等账户 scope |

> 说明：`company.company_id` 是业务代码（如 `C168`），不是 UUID。UUID 与业务代码并存，互不替代。

### 2.2 Migration 示例

新建文件：`database/migrations/20260616_company_account_public_id.sql`

```sql
-- company.public_id
ALTER TABLE `company`
  ADD COLUMN `public_id` CHAR(36) NULL COMMENT 'External opaque id for URLs' AFTER `id`;

UPDATE `company`
SET `public_id` = LOWER(UUID())
WHERE `public_id` IS NULL OR `public_id` = '';

ALTER TABLE `company`
  MODIFY `public_id` CHAR(36) NOT NULL,
  ADD UNIQUE KEY `uk_company_public_id` (`public_id`);

-- account.public_id
ALTER TABLE `account`
  ADD COLUMN `public_id` CHAR(36) NULL COMMENT 'External opaque id for URLs' AFTER `id`;

UPDATE `account`
SET `public_id` = LOWER(UUID())
WHERE `public_id` IS NULL OR `public_id` = '';

ALTER TABLE `account`
  MODIFY `public_id` CHAR(36) NOT NULL,
  ADD UNIQUE KEY `uk_account_public_id` (`public_id`);
```

### 2.3 部署顺序

1. 在测试库执行 migration，确认无重复 UUID。
2. 生产库执行 migration（建议在低峰期）。
3. 部署后端解析代码（同时兼容旧 `company_id` 数字参数）。
4. 部署前端（先读 UUID，fallback 旧参数）。
5. 观察 1～2 周后，再考虑废弃 URL 中的数字 `company_id`。

---

## 3. 后端：统一 Scope 解析层

### 3.1 新建 PHP Helper

建议新建：`includes/public_scope_resolve.php`

职责：

1. `resolve_company_public_id(PDO $pdo, string $publicId): ?int` — UUID → `company.id`
2. `resolve_account_public_id(PDO $pdo, string $publicId): ?int` — UUID → `account.id`
3. `resolve_request_company_id_from_params(PDO $pdo, array $params): int` — 统一入口：
   - 优先读 `company_public_id`（或路径段）
   - 其次读数字 `company_id`（兼容旧链接）
   - 否则 fallback `$_SESSION['company_id']`
   - 每一步之后调用现有权限函数

示例骨架：

```php
<?php
// includes/public_scope_resolve.php

function ps_normalize_uuid(?string $raw): ?string {
    if ($raw === null || $raw === '') return null;
    $u = strtolower(trim($raw));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $u)
        ? $u : null;
}

function resolve_company_public_id(PDO $pdo, string $publicId): ?int {
    $uuid = ps_normalize_uuid($publicId);
    if ($uuid === null) return null;
    $stmt = $pdo->prepare('SELECT id FROM company WHERE public_id = ? LIMIT 1');
    $stmt->execute([$uuid]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

function resolve_account_public_id(PDO $pdo, string $publicId): ?int {
    $uuid = ps_normalize_uuid($publicId);
    if ($uuid === null) return null;
    $stmt = $pdo->prepare('SELECT id FROM account WHERE public_id = ? LIMIT 1');
    $stmt->execute([$uuid]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

/**
 * 替代各 API 里散落的 $_GET['company_id'] 解析。
 * @return int 已验权的公司内部 id；失败应 throw Exception
 */
function resolve_request_company_id_unified(PDO $pdo, array $params): int {
    if (!empty($params['company_public_id'])) {
        $cid = resolve_company_public_id($pdo, (string) $params['company_public_id']);
        if ($cid === null) {
            throw new Exception('无效的公司标识');
        }
        // 复用现有 tx_resolve_request_company_id 的验权逻辑
        return tx_resolve_request_company_id($pdo, ['company_id' => $cid] + $params);
    }

    if (isset($params['company_id']) && $params['company_id'] !== '') {
        return tx_resolve_request_company_id($pdo, $params);
    }

    $sessionCid = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : 0;
    if ($sessionCid > 0) {
        return tx_resolve_request_company_id($pdo, ['company_id' => $sessionCid] + $params);
    }

    throw new Exception('缺少公司上下文');
}
```

> 实现时直接 `require_once` 现有的 `api/transactions/transaction_scope.php`，避免重复验权代码。

### 3.2 API 返回中附带 `public_id`

修改公司列表、账户列表 API，在 JSON 中增加 `public_id`，供前端构建 URL：

| API 文件 | 改动 |
|----------|------|
| `api/get_companies_helper.php` | `SELECT` 增加 `c.public_id` |
| `api/accounts/accountlistapi.php` | 账户行增加 `public_id` |
| `api/session/current_user_api.php` | `company` 对象增加 `public_id` |
| `api/transactions/search_api.php` | `account_db_id` 旁增加 `account_public_id`（可选别名） |

示例响应字段：

```json
{
  "id": 5,
  "public_id": "a3f2b1c4-8e9d-4a1b-9c2d-1e5f6a7b8c9d",
  "company_id": "C168"
}
```

### 3.3 需要接入统一解析的 API（首批）

以下文件当前直接读 `$_GET['company_id']`，应逐步改为 `resolve_request_company_id_unified`：

- `api/accounts/accountlistapi.php`
- `api/transactions/dashboard_api.php`
- `api/transactions/dashboard_bootstrap_api.php`
- `api/transactions/search_api.php`
- `api/processes/processlist_api.php`
- `api/session/update_company_session_api.php`（可新增 `company_public_id` 参数）

**兼容策略**：旧参数 `company_id=5` 继续有效至少一个版本周期。

---

## 4. 前端：方案 1 — Session 状态，清洁 URL

### 4.1 已有基础设施（可直接复用）

项目里已有 dashboard 公司筛选的 session 机制，位于：

`frontend/src/utils/company/sharedCompanyFilter.js`

关键常量：

| Key | 用途 |
|-----|------|
| `dashboard_group_filter` | 当前 group / company 筛选 JSON |
| `dashboard_selected_company_id` | 上次选中的公司内部 id |
| `ec_dashboard_tab_bootstrap` | 新标签页一次性 bootstrap（localStorage） |

已有函数：

- `stashDashboardFilterForNewTab()` — 打开新标签前快照 session
- `consumeDashboardFilterNewTabBootstrap()` — 新标签消费快照

### 4.2 新建 URL Scope 工具（建议）

新建：`frontend/src/utils/company/urlScope.js`

```javascript
import { readPersistedDashboardGcFilter } from "./sharedCompanyFilter.js";

/** 页面初始化：解析 URL，但不再把 company_id 写回地址栏 */
export function resolvePageCompanyScope(searchParams, sessionMe) {
  // 1. 新标签 bootstrap（优先）
  // consumeDashboardFilterNewTabBootstrap() 应在 App/layout 入口已调用

  // 2. UUID（方案 2，见下文）
  const companyPublicId = searchParams.get("company_public_id");
  if (companyPublicId) {
    return { source: "url-uuid", companyPublicId };
  }

  // 3. 旧链接兼容（过渡期）
  const legacy = searchParams.get("company_id");
  if (legacy) {
    const n = Number(legacy);
    if (Number.isFinite(n) && n > 0) {
      return { source: "url-legacy", companyId: n, stripFromUrl: true };
    }
  }

  // 4. Session / persisted filter（方案 1 默认）
  const persisted = readPersistedDashboardGcFilter();
  const companyId =
    persisted?.companyId ??
    (sessionMe?.company_id != null ? Number(sessionMe.company_id) : null);

  return { source: "session", companyId };
}

/** 同步地址栏：去掉 company_id，保持路径干净 */
export function stripLegacyScopeFromUrl(navigate, { keep = [] } = {}) {
  const url = new URL(window.location.href);
  const remove = ["company_id", "account_db_id", "account_code", "account_name"];
  for (const key of remove) {
    if (!keep.includes(key)) url.searchParams.delete(key);
  }
  const next = `${url.pathname}${url.search}${url.hash}`;
  const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (next !== current) navigate(next, { replace: true });
}
```

### 4.3 页面改造模式（以 Account List 为例）

当前写 URL 的位置：`frontend/src/pages/account/AccountListPage.jsx`（多处 `url.searchParams.set("company_id", ...)`）。

**改造步骤：**

1. **初始化**：用 `resolvePageCompanyScope()` 得到 `companyId`，不再从 URL 读取为唯一来源。
2. **切换公司 pill**：只更新 `sessionStorage`（`sharedCompanyFilter` 已有逻辑）+ 调 `syncCompanySessionInBackground`（`companySessionSwitchCore.js`）。
3. **删除** `useEffect` 里把 `company_id` 写回 `window.location` 的代码。
4. **过渡期**：若 URL 带旧 `?company_id=5`，读一次后 `stripLegacyScopeFromUrl()` 清掉。

同样模式适用于：

| 文件 | 写 `company_id` 到浏览器 URL 的次数（约） |
|------|------------------------------------------|
| `AccountListPage.jsx` | 8+ |
| `ProcessListPage.jsx` | 3 |
| `useBankProcessListPage.js` | 6 |
| `useTransactionData.js` | 3 |
| `UserListPage.jsx` | 1 |

> API 请求 URL（`buildApiUrl('api/...')`）**可以继续传数字 `company_id`**，因为那不会出现在浏览器地址栏。重点是 **浏览器 location bar** 的清洁。

### 4.4 新标签页打开（站内链接）

在 `<a target="_blank">` 或 `window.open` 之前调用：

```javascript
import { stashDashboardFilterForNewTab } from "../../utils/company/sharedCompanyFilter.js";

function openInNewTab(path) {
  stashDashboardFilterForNewTab();
  window.open(path, "_blank", "noopener,noreferrer");
}
```

在 `AuthenticatedLayout.jsx` 或 `main.jsx` 入口确保：

```javascript
import { consumeDashboardFilterNewTabBootstrap } from "./utils/company/sharedCompanyFilter.js";
consumeDashboardFilterNewTabBootstrap();
```

---

## 5. 前端：方案 2 — UUID 用于可分享链接

### 5.1 参数命名约定

| 旧参数 | 新参数 | 示例 |
|--------|--------|------|
| `company_id=5` | `company_public_id` | `a3f2b1c4-8e9d-...` |
| `account_db_id=4837` | `account_public_id` | `f7e6d5c4-...` |

路径式（可选，更美观）：

```
/transaction/payment-history/{companyPublicId}/{accountPublicId}?date_from=...&date_to=...
```

需在 `frontend/src/App.jsx` 增加路由：

```jsx
<Route
  path="/transaction/payment-history/:companyPublicId/:accountPublicId"
  element={<TransactionPaymentHistoryPage />}
/>
```

### 5.2 改造 Payment History URL 构建

文件：`frontend/src/pages/transaction/lib/transactionPaymentHistoryUrl.js`

当前 `buildPaymentHistoryUrl` 写入大量明文参数。改为：

```javascript
export function buildPaymentHistoryUrl({ row, dateFrom, dateTo, scopeApi, opts = {} }) {
  const companyPublicId = scopeApi?.companyPublicId ?? row?.company_public_id;
  const accountPublicId = row?.account_public_id ?? row?.public_id;

  if (companyPublicId && accountPublicId) {
    const params = new URLSearchParams();
    if (dateFrom) params.set("date_from", String(dateFrom));
    if (dateTo) params.set("date_to", String(dateTo));
    // 仅保留筛选类参数，不放内部 id
    const qs = params.toString();
    return `/transaction/payment-history/${companyPublicId}/${accountPublicId}${qs ? `?${qs}` : ""}`;
  }

  // 过渡期 fallback：旧格式 + stash bootstrap
  stashDashboardFilterForNewTab();
  // ... 现有逻辑 ...
}
```

解析侧 `parsePaymentHistoryParams` 增加从 `useParams()` 读取 UUID 的路径。

### 5.3 前端 UUID → 内部 ID 的两种策略

**策略 A（推荐）**：页面只把 UUID 传给 API，后端解析。前端不需要查表。

```javascript
// transactionApi.js
params.set("company_public_id", companyPublicId);
params.set("account_public_id", accountPublicId);
```

**策略 B**：登录后公司列表已缓存 `public_id ↔ id` 映射，前端本地解析后再调旧 API。减少后端改动，但映射必须来自服务端且随权限过滤。

### 5.4 `tenantLedgerParams.js` 扩展

文件：`frontend/src/utils/company/tenantLedgerParams.js`

在 `applyTenantLedgerToParams` 中，当 scope 含 `companyPublicId` 时写 `company_public_id`，否则写数字 `company_id`（API 层）或什么都不写（浏览器 URL 层，由 session 提供）。

建议拆成两个函数：

- `applyTenantLedgerToApiParams()` — 给 fetch 用，可含内部 id
- `applyTenantLedgerToBrowserUrl()` — 给 `navigate()` 用，只含 UUID 或为空

---

## 6. 分阶段实施计划

### Phase 0 — 准备（1～2 天）

- [ ] 执行 DB migration，回填 UUID
- [ ] 公司/账户 API 返回 `public_id`
- [ ] 新建 `includes/public_scope_resolve.php`
- [ ] 新建 `frontend/src/utils/company/urlScope.js`

### Phase 1 — 方案 1：清洁站内 URL（3～5 天）

- [ ] `AuthenticatedLayout` 入口调用 `consumeDashboardFilterNewTabBootstrap`
- [ ] Account List 去掉地址栏 `company_id`（保留 API 参数）
- [ ] Dashboard、Process List、Bank Process List 同上
- [ ] 旧链接 `?company_id=5` 读一次后 `replaceState` 清除
- [ ] 回归：切换公司 pill、刷新、前进/后退

### Phase 2 — 方案 2：Payment History UUID（2～4 天）

- [ ] `search_api` / 相关 API 返回 `account_public_id`
- [ ] 新路由 `/transaction/payment-history/:companyPublicId/:accountPublicId`
- [ ] 改写 `buildPaymentHistoryUrl` / `parsePaymentHistoryParams`
- [ ] 后端 `search_api` 支持 `account_public_id`
- [ ] 旧 query 链接仍可用（读 `company_id` + `account_db_id`）

### Phase 3 — 收尾（可选）

- [ ] 其余 maintenance / datacapture 页面统一
- [ ] 文档告知用户旧书签会 redirect
- [ ] 移除前端 fallback 代码（确认无旧链接流量后）

---

## 7. 测试清单

### 7.1 功能

- [ ] 登录后打开 `/account-list`，地址栏无 `company_id`
- [ ] 切换 Company pill（如 C168），列表数据正确，URL 仍无 `company_id`
- [ ] F5 刷新后公司筛选与列表一致
- [ ] 中键新标签打开 Account List，公司与筛选正确（bootstrap）
- [ ] Payment History 新 UUID 链接可打开且数据正确
- [ ] 旧链接 `?company_id=5&account_db_id=4837` 仍可打开（过渡期）

### 7.2 安全

- [ ] 未登录访问 UUID 链接 → 跳转登录
- [ ] 登录但无权限的 UUID → API 返回「无权访问」
- [ ] 篡改 UUID 任意字符 → 404 / 无效标识
- [ ] 不能通过递增数字 `company_id` 越权（旧接口仍要验权）

### 7.3 部署

- [ ] `cd frontend && npm run build` 通过
- [ ] 生产 migration 已执行
- [ ] 回滚方案：前端可独立回滚；DB `public_id` 列可保留不影响旧逻辑

---

## 8. 常见问题

### Q1：UUID 放在 URL 里，别人复制链接还能看数据吗？

能打开链接的人，在 Session 有效期内仍可能看到数据。**权限校验才是防线**；UUID 只是不暴露「这是第 5 号公司」这种结构信息。

### Q2：要不要把 API 请求里的 `company_id` 也改成 UUID？

不是必须。地址栏干净后，Network 里仍可能看到 API 参数。若也要隐藏，需全 API 改用 `company_public_id`，工作量更大，可作为 Phase 3。

### Q3：`company.company_id`（C168）和 UUID 用哪个？

- **C168**：人类可读，适合内部沟通，但仍可猜测。
- **UUID**：适合对外分享、书签、Payment History。
- 建议：**站内用 session，分享用 UUID**，不强迫把 C168 放进 URL。

### Q4：Payment History 日期等筛选参数要隐藏吗？

日期、`view_group=AP` 等业务筛选可保留在 query string；优先隐藏的是 **数据库主键**（`company_id`、`account_db_id`）。

---

## 9. 关键文件索引

| 类型 | 路径 |
|------|------|
| 路由 | `frontend/src/App.jsx` |
| Session 筛选 | `frontend/src/utils/company/sharedCompanyFilter.js` |
| API 公司参数 | `frontend/src/utils/company/tenantLedgerParams.js` |
| 公司 Session 同步 | `frontend/src/utils/company/companySessionSwitchCore.js` |
| Account URL 构建 | `frontend/src/pages/account/accountLogic.js`、`AccountListPage.jsx` |
| Payment History URL | `frontend/src/pages/transaction/lib/transactionPaymentHistoryUrl.js` |
| 后端公司验权 | `api/transactions/transaction_scope.php` |
| 公司列表 | `api/get_companies_helper.php` |

---

## 10. 最小可行第一步（建议本周完成）

若希望最快看到效果，按此顺序做即可：

1. 跑 migration，给 `company` 表加 `public_id`。
2. `get_companies_helper.php` 返回 `public_id`。
3. 只改 **Account List**：初始化从 session 读公司，删除写 `company_id` 到地址栏的代码；旧 URL 自动清除参数。
4. 手动测试切换公司 + 刷新。

完成后再做 Payment History 的 UUID 路由与 `account.public_id`。

---

*文档版本：2026-06-16 · 适用于 count168test / EazyCount SPA + PHP API 架构*
