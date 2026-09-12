<?php
// Function to check if active
if (!function_exists('is_active_page')) {
    function is_active_page($pages)
    {
        $current_page = basename($_SERVER['PHP_SELF']);
        if (is_array($pages)) {
            return in_array($current_page, $pages) ? 'active' : '';
        }
        return ($current_page == $pages) ? 'active' : '';
    }
}

// Determine paths based on directory depth
$is_reports_dir = (strpos($_SERVER['PHP_SELF'], '/reports/') !== false);
$base_path = $is_reports_dir ? '../' : '';
$icon_path = $is_reports_dir ? '../../icons/' : '../icons/';
$report_path = $is_reports_dir ? '' : 'reports/';
?>

<aside class="sidebar">
    <div class="logo-area">
        <img src="<?php echo $icon_path; ?>logo-light(1).png" alt="JOF Logo" class="brand-logo">
    </div>

    <div class="nav-label">MENU</div>
    <nav class="navigation">
        <a href="<?php echo $base_path; ?>dashboard.php"
            class="nav-link <?php echo is_active_page('dashboard.php'); ?>">
            <img src="<?php echo $icon_path; ?>dashboard.png" alt="Dashboard" width="20">
            <span>Dashboard</span>
        </a>

        <div
            class="nav-item-dropdown <?php echo is_active_page(['pt_session.php', 'personal_training.php', 'inactive_pt.php', 'member_pt_details.php']); ?>">
            <a href="<?php echo $base_path; ?>pt_session.php"
                class="nav-link <?php echo is_active_page(['pt_session.php', 'personal_training.php', 'inactive_pt.php', 'member_pt_details.php']); ?>">
                <img src="<?php echo $icon_path; ?>calendar.png" alt="PT Sessions" width="20">
                <span>PT Sessions</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>pt_session.php"
                    class="<?php echo is_active_page('pt_session.php'); ?>">View PT Sessions</a>
                <a href="<?php echo $base_path; ?>personal_training.php"
                    class="<?php echo is_active_page(['personal_training.php', 'member_pt_details.php']); ?>">Personal
                    Training (PT)</a>
                <a href="<?php echo $base_path; ?>inactive_pt.php"
                    class="<?php echo is_active_page('inactive_pt.php'); ?>">Inactive PT</a>
            </div>
        </div>

        <div class="nav-item-dropdown <?php echo is_active_page(['view_consultation.php', 'consultation.php']); ?>">
            <a href="<?php echo $base_path; ?>view_consultation.php"
                class="nav-link <?php echo is_active_page(['view_consultation.php', 'consultation.php']); ?>">
                <img src="<?php echo $icon_path; ?>clipboard-user-solid-full.svg" alt="Consultation" width="20">
                <span>Consultation</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>consultation.php"
                    class="<?php echo is_active_page('consultation.php'); ?>">Book Consultation</a>
                <a href="<?php echo $base_path; ?>view_consultation.php"
                    class="<?php echo is_active_page('view_consultation.php'); ?>">View Consultations</a>
            </div>
        </div>

        <?php if (in_array(($_SESSION['user_role'] ?? ''), ['admin', 'trainer'], true)): ?>
        <div
            class="nav-item-dropdown <?php echo is_active_page(['user_info.php', 'user_details.php', 'user_recycle_bin.php']); ?>">
            <a href="<?php echo $base_path; ?>user_info.php"
                class="nav-link <?php echo is_active_page(['user_info.php', 'user_details.php', 'user_recycle_bin.php']); ?>">
                <img src="<?php echo $icon_path; ?>shield-halved-solid-full.svg" alt="User Information" width="20">
                <span>User Information</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>user_info.php"
                    class="<?php echo is_active_page(['user_info.php', 'user_details.php']); ?>">All Users</a>
                <a href="<?php echo $base_path; ?>user_recycle_bin.php"
                    class="<?php echo is_active_page('user_recycle_bin.php'); ?>">Recycle Bin</a>
            </div>
        </div>
        <?php endif; ?>

        <div
            class="nav-item-dropdown <?php echo is_active_page(['members.php', 'add_member.php', 'inactive_members.php', 'RecycleBin.php', 'person_info.php', 'edit_member.php']); ?>">
            <a href="<?php echo $base_path; ?>members.php"
                class="nav-link <?php echo is_active_page(['members.php', 'add_member.php', 'inactive_members.php', 'RecycleBin.php', 'person_info.php', 'edit_member.php']); ?>">
                <img src="<?php echo $icon_path; ?>users-solid-full.svg" alt="Members" width="20">
                <span>Members</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>add_member.php"
                    class="<?php echo is_active_page('add_member.php'); ?>">Add Member</a>
                <a href="<?php echo $base_path; ?>members.php"
                    class="<?php echo is_active_page(['members.php', 'person_info.php', 'edit_member.php']); ?>">Active
                    Members</a>
                <a href="<?php echo $base_path; ?>inactive_members.php"
                    class="<?php echo is_active_page('inactive_members.php'); ?>">Inactive Members</a>
                <a href="<?php echo $base_path; ?>RecycleBin.php"
                    class="<?php echo is_active_page('RecycleBin.php'); ?>">Recycle Bin</a>
            </div>
        </div>

        <div
            class="nav-item-dropdown <?php echo is_active_page(['diet-plans.php', 'create_diet_plan.php', 'assign_diet_plan.php', 'diet_plan_details.php', 'edit_diet_plan.php', 'diet_messages.php']); ?>">
            <a href="<?php echo $base_path; ?>diet-plans.php"
                class="nav-link <?php echo is_active_page(['diet-plans.php', 'create_diet_plan.php', 'assign_diet_plan.php', 'diet_plan_details.php', 'edit_diet_plan.php', 'diet_messages.php']); ?>">
                <img src="<?php echo $icon_path; ?>balanced-diet.png" alt="Diet Plans" width="20">
                <span>Diet Plans</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>create_diet_plan.php"
                    class="<?php echo is_active_page('create_diet_plan.php'); ?>">Create Diet Plan</a>
                <a href="<?php echo $base_path; ?>assign_diet_plan.php"
                    class="<?php echo is_active_page('assign_diet_plan.php'); ?>">Assign</a>
                <a href="<?php echo $base_path; ?>diet_messages.php"
                    class="<?php echo is_active_page('diet_messages.php'); ?>">Diet Messages</a>
            </div>
        </div>

        <a href="<?php echo $base_path; ?>membership.php"
            class="nav-link <?php echo is_active_page(['membership.php', 'add_membership.php', 'edit_membership.php']); ?>">
            <img src="<?php echo $icon_path; ?>credit-card-regular-full.svg" alt="Membership" width="20">
            <span>Membership</span>
        </a>

        <div
            class="nav-item-dropdown <?php echo is_active_page(['payment_installments.php', 'outstanding_dues.php']); ?>">
            <a href="<?php echo $base_path; ?>payment_installments.php"
                class="nav-link <?php echo is_active_page(['payment_installments.php', 'outstanding_dues.php']); ?>">
                <img src="<?php echo $icon_path; ?>indian-rupee-sign-solid-full.svg" alt="Payments" width="20">
                <span>Payments</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>payment_installments.php"
                    class="<?php echo is_active_page('payment_installments.php'); ?>">Installment Payments</a>
                <a href="<?php echo $base_path; ?>outstanding_dues.php"
                    class="<?php echo is_active_page('outstanding_dues.php'); ?>">Outstanding Dues</a>
            </div>
        </div>

        <div class="nav-item-dropdown <?php echo is_active_page(['sales_leads.php', 'sales_reports.php', 'view_enquiries.php']); ?>">
            <a href="<?php echo $base_path; ?>sales_leads.php"
                class="nav-link <?php echo is_active_page(['sales_leads.php', 'sales_reports.php', 'view_enquiries.php']); ?>">
                <img src="<?php echo $icon_path; ?>trend.png" alt="Sales" width="20">
                <span>Sales Management</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>sales_leads.php"
                    class="<?php echo is_active_page('sales_leads.php'); ?>">New Leads</a>
                <a href="<?php echo $base_path; ?>sales_reports.php"
                    class="<?php echo is_active_page('sales_reports.php'); ?>">Conversion Reports</a>
                <a href="<?php echo $base_path; ?>view_enquiries.php"
                    class="<?php echo is_active_page('view_enquiries.php'); ?>">Contact Enquiries</a>
            </div>
        </div>

        <div
            class="nav-item-dropdown <?php echo is_active_page(['reports_pg.php', 'renewal_report.php', 'revenue_source.php']); ?>">
            <a href="<?php echo $base_path; ?>reports_pg.php"
                class="nav-link <?php echo is_active_page(['reports_pg.php', 'renewal_report.php', 'revenue_source.php']); ?>">
                <img src="<?php echo $icon_path; ?>market-insights.png" alt="Reports" width="20">
                <span>Reports</span>
                <img src="<?php echo $icon_path; ?>chevron-right-solid-full.svg" class="fa-solid fa-chevron-right ms-auto nav-arrow">
            </a>
            <div class="sidebar-submenu">
                <a href="<?php echo $base_path; ?>renewal_report.php"
                    class="<?php echo is_active_page('renewal_report.php'); ?>">Renewal Report</a>
                <a href="<?php echo $base_path; ?>revenue_source.php"
                    class="<?php echo is_active_page('revenue_source.php'); ?>">Revenue Report</a>
            </div>
        </div>
    </nav>
</aside>