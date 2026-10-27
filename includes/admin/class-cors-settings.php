<?php
/**
 * CORS Settings Admin Page
 *
 * Provides a UI to view pending origin requests and accept/decline them.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_CORS_Settings {

    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_form_submission']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Handicraft Auth', 'handicraft-auth'),
            __('Handicraft Auth', 'handicraft-auth'),
            'manage_options',
            'hin-auth',
            [$this, 'render_cors_settings_page'],
            'dashicons-shield',
            65
        );

        add_submenu_page(
            'hin-auth',
            __('CORS Settings', 'handicraft-auth'),
            __('CORS Settings', 'handicraft-auth'),
            'manage_options',
            'hin-auth',
            [$this, 'render_cors_settings_page']
        );
    }

    public function handle_form_submission() {
        if (!isset($_POST['hin_cors_nonce']) || !wp_verify_nonce($_POST['hin_cors_nonce'], 'hin_cors_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $allowed_origins = get_option('hin_cors_allowed_origins', []);
        $pending_origins = get_option('hin_cors_pending_origins', []);
        $updated = false;

        // Handle Add Manual Origin
        if (isset($_POST['add_origin']) && !empty($_POST['new_origin'])) {
            $new_origin = sanitize_text_field($_POST['new_origin']);
            if (!in_array($new_origin, $allowed_origins, true)) {
                $allowed_origins[] = rtrim($new_origin, '/'); // Remove trailing slash
                $updated = true;
            }
        }

        // Handle Accept Pending
        if (isset($_POST['accept_origin']) && !empty($_POST['origin'])) {
            $origin = sanitize_text_field($_POST['origin']);
            if (!in_array($origin, $allowed_origins, true)) {
                $allowed_origins[] = $origin;
                $updated = true;
            }
            // Remove from pending
            $pending_origins = array_diff($pending_origins, [$origin]);
        }

        // Handle Decline Pending
        if (isset($_POST['decline_origin']) && !empty($_POST['origin'])) {
            $origin = sanitize_text_field($_POST['origin']);
            // Just remove from pending
            $pending_origins = array_diff($pending_origins, [$origin]);
            $updated = true;
        }

        // Handle Remove Allowed
        if (isset($_POST['remove_allowed']) && !empty($_POST['origin'])) {
            $origin = sanitize_text_field($_POST['origin']);
            $allowed_origins = array_diff($allowed_origins, [$origin]);
            $updated = true;
        }

        if ($updated) {
            update_option('hin_cors_allowed_origins', array_values(array_unique($allowed_origins)));
            update_option('hin_cors_pending_origins', array_values(array_unique($pending_origins)));
            wp_redirect(admin_url('admin.php?page=hin-auth&updated=true'));
            exit;
        }
    }

    public function render_cors_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $allowed_origins = get_option('hin_cors_allowed_origins', []);
        $pending_origins = get_option('hin_cors_pending_origins', []);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Handicraft Auth CORS Settings', 'handicraft-auth'); ?></h1>
            <p><?php esc_html_e('Manage Cross-Origin Resource Sharing (CORS) access for Headless frontend applications.', 'handicraft-auth'); ?></p>

            <?php if (isset($_GET['updated']) && $_GET['updated'] === 'true') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings updated successfully.', 'handicraft-auth'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Pending Requests', 'handicraft-auth'); ?></h2>
            <p class="description"><?php esc_html_e('These origins have attempted to connect to the REST API but are currently blocked by CORS.', 'handicraft-auth'); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Origin', 'handicraft-auth'); ?></th>
                        <th style="width: 200px;"><?php esc_html_e('Actions', 'handicraft-auth'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pending_origins)) : ?>
                        <tr>
                            <td colspan="2"><?php esc_html_e('No pending requests.', 'handicraft-auth'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($pending_origins as $origin) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($origin); ?></strong></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('hin_cors_action', 'hin_cors_nonce'); ?>
                                        <input type="hidden" name="origin" value="<?php echo esc_attr($origin); ?>">
                                        <button type="submit" name="accept_origin" class="button button-primary"><?php esc_html_e('Accept', 'handicraft-auth'); ?></button>
                                        <button type="submit" name="decline_origin" class="button"><?php esc_html_e('Decline', 'handicraft-auth'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <hr style="margin: 30px 0;">

            <h2><?php esc_html_e('Allowed Origins', 'handicraft-auth'); ?></h2>
            <p class="description"><?php esc_html_e('These origins are allowed to make requests to the REST API. Note: Localhost addresses are always allowed by default in development.', 'handicraft-auth'); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Origin', 'handicraft-auth'); ?></th>
                        <th style="width: 200px;"><?php esc_html_e('Actions', 'handicraft-auth'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allowed_origins)) : ?>
                        <tr>
                            <td colspan="2"><?php esc_html_e('No explicitly allowed origins.', 'handicraft-auth'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($allowed_origins as $origin) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($origin); ?></strong></td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('hin_cors_action', 'hin_cors_nonce'); ?>
                                        <input type="hidden" name="origin" value="<?php echo esc_attr($origin); ?>">
                                        <button type="submit" name="remove_allowed" class="button button-link-delete"><?php esc_html_e('Remove', 'handicraft-auth'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h3><?php esc_html_e('Manually Add Origin', 'handicraft-auth'); ?></h3>
                <form method="post">
                    <?php wp_nonce_field('hin_cors_action', 'hin_cors_nonce'); ?>
                    <input type="url" name="new_origin" placeholder="https://example.com" class="regular-text" required>
                    <button type="submit" name="add_origin" class="button button-secondary"><?php esc_html_e('Add Origin', 'handicraft-auth'); ?></button>
                    <p class="description"><?php esc_html_e('Enter the full URL including scheme, e.g., https://your-nuxt-site.com', 'handicraft-auth'); ?></p>
                </form>
            </div>
        </div>
        <?php
    }
}
