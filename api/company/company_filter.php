<?php
/**
 * Shared Company Filter Component (SSR)
 * Needs variables:
 * - $user_companies: Array of companies properly fetched with $fetchAll=true
 * - $company_id: The currently active company_id
 * - $filter_prefix: Prefix for CSS classes, default 'account'. 
 */

$filter_prefix = $filter_prefix ?? 'account'; 
$hide_group_filter = $hide_group_filter ?? false;

// Group companies by group_id
$shared_groups = [];
$has_independent = false;
foreach ($user_companies as $comp) {
    if (!empty($comp['company_id'])) {
        $gid = strtoupper(trim($comp['group_id'] ?? ''));
        if (!empty($gid)) {
            $shared_groups[$gid] = true;
        } else {
            $has_independent = true;
        }
    }
}
$shared_groups = array_keys($shared_groups);
sort($shared_groups);

// Determine active group based on $company_id
$active_group_id = null;
foreach ($user_companies as $comp) {
    if ($comp['id'] == $company_id) {
        $active_group_id = strtoupper(trim($comp['group_id'] ?? ''));
        break;
    }
}

// 检查 sessionStorage 中的状态以避免首次加载页面时服务器知道的和浏览器 session 不一致。
// 这里我们在 JS 中处理，SSR 默认渲染 当前 company_id 的组别。
?>

<!-- Group Buttons (above Company) -->
<?php if (count($shared_groups) > 0 && !$hide_group_filter): ?>
<div id="group-buttons-wrapper" class="<?php echo $filter_prefix; ?>-company-filter shared-group-wrapper">
    <span class="<?php echo $filter_prefix; ?>-company-label">Group ID:</span>
    <div id="group-buttons-container" class="<?php echo $filter_prefix; ?>-company-buttons">
        <?php foreach ($shared_groups as $gid): ?>
            <button type="button" 
                    class="<?php echo $filter_prefix; ?>-company-btn shared-group-btn <?php echo ($active_group_id === $gid) ? 'active' : ''; ?>" 
                    data-group-id="<?php echo htmlspecialchars($gid); ?>">
                <?php echo htmlspecialchars($gid); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Company Buttons -->
<?php if (count($user_companies) > 0): ?>
<div id="company-buttons-wrapper" class="<?php echo $filter_prefix; ?>-company-filter shared-company-wrapper">
    <span class="<?php echo $filter_prefix; ?>-company-label">Company:</span>
    <div id="company-buttons-container" class="<?php echo $filter_prefix; ?>-company-buttons">
        <?php foreach ($user_companies as $comp): 
            $c_gid = strtoupper(trim($comp['group_id'] ?? ''));
            $display_style = '';
            if ($hide_group_filter) {
                $display_style = '';
            } else {
                if (!empty($active_group_id)) {
                    $display_style = ($c_gid === $active_group_id) ? '' : 'display: none;';
                } else {
                    $display_style = empty($c_gid) ? '' : 'display: none;';
                }
            }
        ?>
            <button type="button" 
                    style="<?php echo $display_style; ?>"
                    class="<?php echo $filter_prefix; ?>-company-btn shared-company-btn <?php echo ($comp['id'] == $company_id) ? 'active' : ''; ?>" 
                    data-company-id="<?php echo $comp['id']; ?>"
                    data-group-id="<?php echo htmlspecialchars($c_gid); ?>"
                    data-company-code="<?php echo htmlspecialchars($comp['company_id']); ?>"
                    >
                <?php echo htmlspecialchars($comp['company_id']); ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
