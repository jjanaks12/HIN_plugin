<?php
/**
 * Contact Inquiry REST API Routes
 *
 * Handles public contact form inquiries, notifications, and customer communications.
 *
 * @package   Handicraft_Auth
 * @namespace handicraft/v1
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Contact_Routes {

    const NAMESPACE = 'handicraft/v1';

    /**
     * Register REST API routes for Contact.
     */
    public function register_routes() {
        /**
         * @route   POST /wp-json/handicraft/v1/contact
         * @desc    Submit customer contact inquiry message.
         * @auth    Public
         */
        register_rest_route(self::NAMESPACE, '/contact', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'handle_submit_contact'],
            'permission_callback' => '__return_true',
            'args'                => [
                'name'    => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return !empty(trim($param));
                    },
                ],
                'email'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function($param) {
                        return is_email($param);
                    },
                ],
                'phone'   => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'subject' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => 'General Inquiry',
                ],
                'message' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                    'validate_callback' => function($param) {
                        return !empty(trim($param));
                    },
                ],
            ],
        ]);
    }

    /**
     * Process contact form submission and dispatch notification email.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_submit_contact(WP_REST_Request $request): WP_REST_Response {
        $name    = trim($request->get_param('name') ?? '');
        $email   = trim($request->get_param('email') ?? '');
        $phone   = trim($request->get_param('phone') ?? '');
        $subject = trim($request->get_param('subject') ?? 'General Inquiry');
        $message = trim($request->get_param('message') ?? '');

        if (empty($name) || empty($email) || empty($message)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Please fill in all required fields (Name, Email, and Message).', 'handicraft-auth'),
            ], 400);
        }

        if (!is_email($email)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Please provide a valid email address.', 'handicraft-auth'),
            ], 400);
        }

        // Email recipients
        $admin_email = get_option('admin_email');
        $recipients  = ['contact@handicraftsinnepal.com', 'handicraftsinnepal@gmail.com'];
        if (!empty($admin_email) && !in_array($admin_email, $recipients, true)) {
            $recipients[] = $admin_email;
        }

        $email_subject = sprintf('[Handicrafts in Nepal] Contact Form: %s', $subject);
        $email_body    = "You received a new inquiry from the website contact form:\n\n"
            . "----------------------------------------\n"
            . "Name:    " . esc_html($name) . "\n"
            . "Email:   " . esc_html($email) . "\n"
            . "Phone:   " . (!empty($phone) ? esc_html($phone) : 'N/A') . "\n"
            . "Subject: " . esc_html($subject) . "\n"
            . "----------------------------------------\n\n"
            . "Message:\n" . esc_textarea($message) . "\n\n"
            . "----------------------------------------\n"
            . "Submitted on: " . current_time('mysql') . "\n"
            . "IP Address:   " . preg_replace('/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] ?? '') . "\n";

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('Reply-To: %s <%s>', esc_html($name), esc_html($email)),
        ];

        // Send mail via wp_mail (non-fatal if local server doesn't have active sendmail)
        @wp_mail($recipients, $email_subject, $email_body, $headers);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Thank you for contacting us! We have received your message and will get back to you shortly.', 'handicraft-auth'),
        ], 200);
    }
}
