<?php

/**
 * Custom Admin Page to Manage Roles & Assign Roles
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Roles_Admin
{

    public function init()
    {
        add_action('admin_menu', [$this, 'add_roles_submenu']);
        add_action('admin_post_hin_add_role', [$this, 'handle_add_role']);
        add_action('admin_post_hin_remove_role', [$this, 'handle_remove_role']);
        add_action('admin_post_hin_update_user_role', [$this, 'handle_update_user_role']);
        add_action('admin_post_hin_manage_capabilities', [$this, 'handle_manage_capabilities']);
    }

    private function get_predefined_capabilities()
    {
        return [
            'read'                   => 'Read (Basic Access)',
            'add_to_cart'            => 'Add to Cart',
            'view_wholesale_pricing' => 'View Wholesale Pricing',
            'request_custom_order'   => 'Request Custom Order',
        ];
    }

    public function add_roles_submenu()
    {
        add_users_page(
            __('Manage Roles', 'handicraft-auth'),
            __('Roles', 'handicraft-auth'),
            'promote_users', // Only admins can manage roles
            'hin-manage-roles',
            [$this, 'render_roles_page']
        );
    }

    public function render_roles_page()
    {
        if (!current_user_can('promote_users')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        global $wp_roles;
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }

        $all_roles = $wp_roles->get_names();

        // Handle success/error messages
        if (isset($_GET['message'])) {
            $msg = sanitize_text_field($_GET['message']);
            if ($msg === 'role_added') echo '<div class="updated"><p>Role added successfully.</p></div>';
            if ($msg === 'role_removed') echo '<div class="updated"><p>Role removed successfully.</p></div>';
            if ($msg === 'user_updated') echo '<div class="updated"><p>User role updated successfully.</p></div>';
            if ($msg === 'caps_managed') echo '<div class="updated"><p>Role capabilities updated successfully.</p></div>';
            if ($msg === 'error') echo '<div class="error"><p>An error occurred. Please try again.</p></div>';
            if ($msg === 'user_not_found') echo '<div class="error"><p>User not found.</p></div>';
        }
?>
        <div class="wrap">
            <h1><?php _e('Manage Roles', 'handicraft-auth'); ?></h1>

            <div style="display:flex; gap: 40px; margin-top: 20px;">

                <!-- Add New Role Form -->
                <div style="flex:1; max-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2><?php _e('Add New Role', 'handicraft-auth'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('hin_add_role_action', 'hin_add_role_nonce'); ?>
                        <input type="hidden" name="action" value="hin_add_role">

                        <p>
                            <label for="role_slug"><strong>Role Slug (ID):</strong></label><br />
                            <input type="text" name="role_slug" id="role_slug" required pattern="[a-z0-9_]+" title="Only lowercase letters, numbers, and underscores." style="width:100%;">
                            <small>e.g., <code>b2b_buyer</code> (No spaces or capitals)</small>
                        </p>

                        <p>
                            <label for="role_name"><strong>Display Name:</strong></label><br />
                            <input type="text" name="role_name" id="role_name" required style="width:100%;">
                            <small>e.g., <code>B2B Buyer</code></small>
                        </p>

                        <p>
                            <label for="clone_capabilities"><strong>Clone Capabilities From:</strong></label><br />
                            <select name="clone_capabilities" id="clone_capabilities" style="width:100%;">
                                <option value="subscriber">Subscriber (Basic Read)</option>
                                <option value="customer">WooCommerce Customer</option>
                                <?php foreach ($all_roles as $slug => $name): ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <?php submit_button(__('Add Role', 'handicraft-auth'), 'primary'); ?>
                    </form>
                </div>

                <!-- Update User Role Form -->
                <div style="flex:1; max-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2><?php _e('Update User Role', 'handicraft-auth'); ?></h2>
                    <p><em>(You can also do this from the standard <a href="users.php">All Users</a> page)</em></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('hin_update_user_role_action', 'hin_update_user_role_nonce'); ?>
                        <input type="hidden" name="action" value="hin_update_user_role">

                        <p>
                            <label for="user_email"><strong>Search User Email/Name:</strong></label><br />
                            <input type="text" list="hin_user_list" name="user_email" id="user_email" required style="width:100%;" placeholder="Type to search users..." autocomplete="off">
                            <datalist id="hin_user_list">
                                <?php
                                $users = get_users(['number' => 1000]); // Fetch full user objects for roles
                                $user_roles_map = [];
                                foreach ($users as $u) {
                                    $primary_role = !empty($u->roles) ? $u->roles[0] : 'None';
                                    $user_roles_map[$u->user_email] = $primary_role;

                                    $role_label = isset($all_roles[$primary_role]) ? $all_roles[$primary_role] : $primary_role;
                                    echo '<option value="' . esc_attr($u->user_email) . '">' . esc_html($u->display_name . ' (' . $u->user_email . ') - Role: ' . $role_label) . '</option>';
                                }
                                ?>
                            </datalist>
                        </p>

                        <p>
                            <label for="new_role"><strong>Assign Role:</strong></label><br />
                            <select name="new_role" id="new_role" style="width:100%;">
                                <?php foreach ($all_roles as $slug => $name): ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <script>
                            document.addEventListener('DOMContentLoaded', function() {
                                const userRolesMap = <?php echo json_encode($user_roles_map); ?>;
                                const emailInput = document.getElementById('user_email');
                                const roleSelect = document.getElementById('new_role');

                                emailInput.addEventListener('input', function() {
                                    const email = this.value.trim();
                                    if (userRolesMap[email]) {
                                        roleSelect.value = userRolesMap[email];
                                    }
                                });
                            });
                        </script>

                        <?php submit_button(__('Update User', 'handicraft-auth'), 'secondary'); ?>
                    </form>
                </div>

                <!-- Manage Role Capabilities Form -->
                <div style="flex:1; max-width: 400px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2><?php _e('Manage Capabilities', 'handicraft-auth'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('hin_manage_capabilities_action', 'hin_manage_capabilities_nonce'); ?>
                        <input type="hidden" name="action" value="hin_manage_capabilities">

                        <p>
                            <label for="cap_role"><strong>Select Role:</strong></label><br />
                            <select name="cap_role" id="cap_role" style="width:100%;">
                                <option value="">-- Select a Role --</option>
                                <?php foreach ($all_roles as $slug => $name): ?>
                                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>

                        <div id="capabilities_list" style="display: none;">
                            <p><strong>Capabilities:</strong></p>
                            <?php
                            $predefined_capabilities = $this->get_predefined_capabilities();

                            // Generate JS Map for roles to their capabilities
                            $role_caps_map = [];
                            foreach ($wp_roles->roles as $slug => $details) {
                                $role_caps_map[$slug] = array_keys(array_filter($details['capabilities']));
                            }

                            foreach ($predefined_capabilities as $cap_slug => $cap_name): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="capabilities[]" value="<?php echo esc_attr($cap_slug); ?>" class="hin-cap-cb" data-cap="<?php echo esc_attr($cap_slug); ?>">
                                    <?php echo esc_html($cap_name); ?> <code>(<?php echo esc_html($cap_slug); ?>)</code>
                                </label>
                            <?php endforeach; ?>

                            <p>
                                <?php submit_button(__('Update Capabilities', 'handicraft-auth'), 'secondary'); ?>
                            </p>
                        </div>
                    </form>

                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const roleCapsMap = <?php echo json_encode($role_caps_map); ?>;
                            const capRoleSelect = document.getElementById('cap_role');
                            const capListDiv = document.getElementById('capabilities_list');
                            const checkboxes = document.querySelectorAll('.hin-cap-cb');

                            capRoleSelect.addEventListener('change', function() {
                                const selectedRole = this.value;
                                if (!selectedRole) {
                                    capListDiv.style.display = 'none';
                                    return;
                                }

                                capListDiv.style.display = 'block';
                                const activeCaps = roleCapsMap[selectedRole] || [];

                                checkboxes.forEach(cb => {
                                    cb.checked = activeCaps.includes(cb.dataset.cap);
                                });
                            });
                        });
                    </script>
                </div>
            </div>

            <!-- List Existing Roles -->
            <div style="margin-top: 40px; max-width: 840px;">
                <h2><?php _e('Existing Roles', 'handicraft-auth'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Display Name</th>
                            <th>Role Slug (ID)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_roles as $slug => $name):
                            $is_core = in_array($slug, ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager']);
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong> <?php if ($is_core) echo '<span style="color:#888;">(Core/Plugin)</span>'; ?></td>
                                <td><code><?php echo esc_html($slug); ?></code></td>
                                <td>
                                    <?php if (!$is_core): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Are you sure you want to delete this role? Users with this role will lose their permissions.');">
                                            <?php wp_nonce_field('hin_remove_role_action', 'hin_remove_role_nonce'); ?>
                                            <input type="hidden" name="action" value="hin_remove_role">
                                            <input type="hidden" name="role_slug" value="<?php echo esc_attr($slug); ?>">
                                            <button type="submit" class="button button-link-delete" style="color:#b32d2e;">Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <em>Cannot delete core roles</em>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
<?php
    }

    public function handle_add_role()
    {
        if (!current_user_can('promote_users') || !check_admin_referer('hin_add_role_action', 'hin_add_role_nonce')) {
            wp_die('Unauthorized');
        }

        $role_slug = sanitize_title($_POST['role_slug']);
        $role_name = sanitize_text_field($_POST['role_name']);
        $clone_from = sanitize_text_field($_POST['clone_capabilities']);

        if (empty($role_slug) || empty($role_name)) {
            wp_redirect(admin_url('users.php?page=hin-manage-roles&message=error'));
            exit;
        }

        // Get capabilities from the cloned role
        $capabilities = [];
        $base_role = get_role($clone_from);
        if ($base_role) {
            $capabilities = $base_role->capabilities;
        } else {
            $capabilities = ['read' => true];
        }

        add_role($role_slug, $role_name, $capabilities);

        wp_redirect(admin_url('users.php?page=hin-manage-roles&message=role_added'));
        exit;
    }

    public function handle_remove_role()
    {
        if (!current_user_can('promote_users') || !check_admin_referer('hin_remove_role_action', 'hin_remove_role_nonce')) {
            wp_die('Unauthorized');
        }

        $role_slug = sanitize_title($_POST['role_slug']);

        // Prevent deleting core roles
        $core_roles = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];
        if (in_array($role_slug, $core_roles)) {
            wp_die('You cannot delete core roles.');
        }

        remove_role($role_slug);

        wp_redirect(admin_url('users.php?page=hin-manage-roles&message=role_removed'));
        exit;
    }

    public function handle_update_user_role()
    {
        if (!current_user_can('promote_users') || !check_admin_referer('hin_update_user_role_action', 'hin_update_user_role_nonce')) {
            wp_die('Unauthorized');
        }

        $email = sanitize_email($_POST['user_email']);
        $new_role = sanitize_text_field($_POST['new_role']);

        $user = get_user_by('email', $email);
        if (!$user) {
            wp_redirect(admin_url('users.php?page=hin-manage-roles&message=user_not_found'));
            exit;
        }

        // Set the new role (this overrides existing roles)
        $user->set_role($new_role);

        wp_redirect(admin_url('users.php?page=hin-manage-roles&message=user_updated'));
        exit;
    }

    public function handle_manage_capabilities()
    {
        if (!current_user_can('promote_users') || !check_admin_referer('hin_manage_capabilities_action', 'hin_manage_capabilities_nonce')) {
            wp_die('Unauthorized');
        }

        $role_slug = sanitize_text_field($_POST['cap_role']);
        $submitted_caps = isset($_POST['capabilities']) ? array_map('sanitize_text_field', $_POST['capabilities']) : [];

        $predefined_capabilities = array_keys($this->get_predefined_capabilities());

        $role = get_role($role_slug);
        if ($role) {
            foreach ($predefined_capabilities as $cap) {
                if (in_array($cap, $submitted_caps)) {
                    $role->add_cap($cap);
                } else {
                    $role->remove_cap($cap);
                }
            }
        }

        wp_redirect(admin_url('users.php?page=hin-manage-roles&message=caps_managed'));
        exit;
    }
}
