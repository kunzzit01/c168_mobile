# Deleted log (PHP services)

Moved from `includes/deleted_log*.php`. Used by delete/restore APIs and `deleted_log_list_api.php`.

| File | Role |
|------|------|
| `deleted_log.php` | `deletedLog()` — snapshot row before DELETE |
| `deleted_log_display.php` | List row summary / Acc ID formatting |
| `deleted_log_entry_sources.php` | Entry-tab filter definitions |
| `deleted_log_page_scope.php` | Company visibility scope for list query |

Frontend: `frontend/src/pages/deletedlog/DeletedLogPage.jsx` → `GET ../deleted_log_list_api.php`.
