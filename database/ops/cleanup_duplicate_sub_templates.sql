-- 清理 data_capture_templates 中重复的 sub 行（同一 parent + account + row_index + sub_order）
-- 保留 id 最大（通常最新）的一条。执行前请先备份。
-- 用法：将 @company_id / @process_id 改成实际值后执行。

SET @company_id = 0;   -- 必填：公司 ID
SET @process_id = 0;   -- 必填：process.id（整数）

-- 预览将删除的重复 sub 模板
SELECT t.*
FROM data_capture_templates t
INNER JOIN (
    SELECT
        company_id,
        process_id,
        TRIM(COALESCE(parent_id_product, '')) AS parent_id_product,
        account_id,
        COALESCE(row_index, -1) AS row_index,
        COALESCE(sub_order, 0) AS sub_order,
        MAX(id) AS keep_id
    FROM data_capture_templates
    WHERE product_type = 'sub'
      AND company_id = @company_id
      AND process_id = @process_id
      AND TRIM(COALESCE(parent_id_product, '')) <> ''
    GROUP BY
        company_id,
        process_id,
        TRIM(COALESCE(parent_id_product, '')),
        account_id,
        COALESCE(row_index, -1),
        COALESCE(sub_order, 0)
    HAVING COUNT(*) > 1
) g ON t.company_id = g.company_id
   AND t.process_id = g.process_id
   AND TRIM(COALESCE(t.parent_id_product, '')) = g.parent_id_product
   AND t.account_id = g.account_id
   AND COALESCE(t.row_index, -1) = g.row_index
   AND COALESCE(t.sub_order, 0) = g.sub_order
   AND t.id <> g.keep_id
ORDER BY t.parent_id_product, t.account_id, t.id;

-- 确认无误后取消注释执行删除：
-- DELETE t FROM data_capture_templates t
-- INNER JOIN (
--     SELECT
--         company_id,
--         process_id,
--         TRIM(COALESCE(parent_id_product, '')) AS parent_id_product,
--         account_id,
--         COALESCE(row_index, -1) AS row_index,
--         COALESCE(sub_order, 0) AS sub_order,
--         MAX(id) AS keep_id
--     FROM data_capture_templates
--     WHERE product_type = 'sub'
--       AND company_id = @company_id
--       AND process_id = @process_id
--       AND TRIM(COALESCE(parent_id_product, '')) <> ''
--     GROUP BY
--         company_id,
--         process_id,
--         TRIM(COALESCE(parent_id_product, '')),
--         account_id,
--         COALESCE(row_index, -1),
--         COALESCE(sub_order, 0)
--     HAVING COUNT(*) > 1
-- ) g ON t.company_id = g.company_id
--    AND t.process_id = g.process_id
--    AND TRIM(COALESCE(t.parent_id_product, '')) = g.parent_id_product
--    AND t.account_id = g.account_id
--    AND COALESCE(t.row_index, -1) = g.row_index
--    AND COALESCE(t.sub_order, 0) = g.sub_order
--    AND t.id <> g.keep_id;
