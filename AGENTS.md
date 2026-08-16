# Handicraft Auth Plugin — AI Developer Instructions

## Role & Goal
* **Role:** Senior WordPress & PHP Backend Engineer.
* **Goal:** Maintain and extend `handicraft-auth`, a zero-dependency, secure Headless WordPress authentication and custom REST API plugin for the Nuxt 3 frontend.

## API Route Documentation Standard (MANDATORY)
Whenever creating or modifying any REST API endpoint/route, agents MUST:
* **In-Code Documentation (PHPDoc):**
  * Fully document every route handler: HTTP method, path, required headers (`Authorization`, `X-Country-Code`), query/body arguments (type, required, validation rules, description), and all response codes (`200`, `201`, `400`, `401`, `403`, `500`) with example response schemas.
  * Use WordPress `register_rest_route` parameter schemas (`description`, `type`, `required`, `validate_callback`, `sanitize_callback`).
* **Maintain API Documentation File:**
  * Keep [`API_DOCUMENTATION.md`](file:///Users/janakshrestha/Documents/2026/08August/handcrafts_in_nepal/site/wp-content/plugins/handicraft-auth/API_DOCUMENTATION.md) updated with:
    * Endpoint URL & HTTP method.
    * Headers & Authentication requirements.
    * Request Body JSON schema with examples.
    * Success and error JSON response samples.
    * Equivalent `curl` command.
    * Corresponding TypeScript interface for the Nuxt 3 frontend.

## Core Rules & Constraints
* **Zero Third-Party Plugin Dependency:** Do not introduce composer packages or third-party plugins for auth/roles. Use native PHP and core WordPress APIs (`WP_User`, `WP_REST_Server`, `wp_authenticate`, `hash_hmac`).
* **Modular Structure:**
  * `includes/class-jwt-handler.php`: JWT issuance, validation, and `determine_current_user` hook.
  * `includes/class-user-service.php`: Authentication, registration, and user data sanitization.
  * `includes/class-auth-routes.php`: REST endpoint registration and handlers under namespace `handicraft/v1`.
* **Security & Sanitization:**
  * Always sanitize input parameters (`sanitize_text_field`, `sanitize_email`, `sanitize_key`).
  * Use strict permission callbacks for every registered REST route.
  * Never expose password hashes or sensitive internal WP data in REST responses.
* **Dual Roles & Wholesale Metadata:**
  * Supported registration roles: `customer` (default), `wholesale`.
  * Store wholesale metadata (`billing_company`, `_tax_id`, `billing_phone`, `billing_country`) via `update_user_meta()`.
* **Coding Standards:**
  * Adhere to WordPress PHP Coding Standards (snake_case functions/hooks, PascalCase classes).
  * Use typed methods and return types where supported.
  * Keep explanations concise with bullet points.
