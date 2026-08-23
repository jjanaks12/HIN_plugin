<?php
/**
 * Interactive REST API Documentation Viewer with Server-Rendered Navigation & ScrollSpy
 *
 * Exposes /documentation on the WordPress site.
 * Requires WordPress user authentication.
 *
 * @package Handicraft_Auth
 */

if (!defined('ABSPATH')) {
    exit;
}

class HIN_Documentation_Viewer {

    const ROUTE_SLUG = 'documentation';

    /**
     * Initialize hooks.
     */
    public function init() {
        add_action('init', [$this, 'register_rewrite_rule']);
        add_action('template_redirect', [$this, 'handle_template_redirect']);
        add_filter('query_vars', [$this, 'register_query_vars']);
    }

    /**
     * Register rewrite rule for /documentation and /docs.
     */
    public function register_rewrite_rule() {
        add_rewrite_rule('^' . self::ROUTE_SLUG . '/?$', 'index.php?hin_docs=1', 'top');
        add_rewrite_rule('^docs/?$', 'index.php?hin_docs=1', 'top');
    }

    /**
     * Register query var.
     */
    public function register_query_vars(array $vars): array {
        $vars[] = 'hin_docs';
        return $vars;
    }

    /**
     * Intercept and render /documentation page.
     */
    public function handle_template_redirect() {
        $request_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        $is_docs_route = get_query_var('hin_docs') || $request_path === self::ROUTE_SLUG || $request_path === 'docs';

        if (!$is_docs_route) {
            return;
        }

        // 1. Authentication Guard: Require user to be logged in
        if (!is_user_logged_in()) {
            $redirect_url = site_url('/' . self::ROUTE_SLUG . '/');
            wp_safe_redirect(wp_login_url($redirect_url));
            exit;
        }

        // 2. Read Markdown Documentation File
        $doc_path = HIN_AUTH_PLUGIN_DIR . 'API_DOCUMENTATION.md';
        $markdown_content = '';

        if (file_exists($doc_path)) {
            $markdown_content = file_get_contents($doc_path);
        } else {
            $markdown_content = "# Error\n\nAPI documentation file not found at `" . esc_html($doc_path) . "`.";
        }

        // 3. Parse Markdown into HTML & Structured Table of Contents
        $parsed = $this->parse_markdown($markdown_content);

        $current_user = wp_get_current_user();
        $user_display = $current_user->display_name ?: $current_user->user_login;
        $user_roles   = implode(', ', (array) $current_user->roles);
        $logout_url   = wp_logout_url(site_url('/' . self::ROUTE_SLUG . '/'));
        $site_name    = get_bloginfo('name') ?: 'Handicraft in Nepal';

        // 4. Render Standalone Developer Documentation Page
        $this->render_html($parsed['html'], $parsed['toc'], $user_display, $user_roles, $logout_url, $site_name);
        exit;
    }

    /**
     * Parse Markdown to clean semantic HTML & Table of Contents.
     */
    private function parse_markdown(string $markdown): array {
        $lines = explode("\n", $markdown);
        $html = '';
        $in_code_block = false;
        $code_lang = '';
        $code_content = '';
        $in_table = false;
        $table_header = true;
        $toc = [];

        $endpoint_method_map = [
            '1' => 'POST',
            '2' => 'POST',
            '3' => 'POST',
            '4' => 'GET',
            '5' => 'GET',
            '6' => 'GET',
            '7' => 'GET',
            '8' => 'GET',
            '9' => 'GET',
        ];

        foreach ($lines as $line) {
            // Code Blocks
            if (preg_match('/^```(\w*)/', $line, $m)) {
                if ($in_code_block) {
                    $html .= '<div class="relative group my-5 not-prose">';
                    $html .= '<pre class="language-' . esc_attr($code_lang ?: 'text') . ' rounded-xl overflow-x-auto p-4 bg-[#0b1120] border border-slate-800 text-xs font-mono">';
                    $html .= '<code class="language-' . esc_attr($code_lang ?: 'text') . '">' . htmlspecialchars($code_content, ENT_QUOTES, 'UTF-8') . '</code>';
                    $html .= '</pre>';
                    $html .= '<button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.innerText).then(()=>{this.innerHTML=\'Copied!\';setTimeout(()=>this.innerHTML=\'Copy\',2000)})" class="absolute top-3 right-3 opacity-0 group-hover:opacity-100 transition-all px-2.5 py-1 text-[11px] font-semibold rounded-lg bg-slate-800/90 border border-slate-700 text-slate-300 hover:text-white shadow-sm backdrop-blur-xs">Copy</button>';
                    $html .= '</div>';

                    $in_code_block = false;
                    $code_content = '';
                    $code_lang = '';
                } else {
                    $in_code_block = true;
                    $code_lang = $m[1] ?? 'json';
                    $code_content = '';
                }
                continue;
            }

            if ($in_code_block) {
                $code_content .= ($code_content === '' ? '' : "\n") . $line;
                continue;
            }

            // Tables
            if (preg_match('/^\|(.+)\|$/', trim($line))) {
                if (preg_match('/^\|[\s\-:|]+\|$/', trim($line))) {
                    $table_header = false;
                    continue;
                }
                $cols = array_map('trim', explode('|', trim($line, '|')));
                if (!$in_table) {
                    $in_table = true;
                    $table_header = true;
                    $html .= '<div class="overflow-x-auto my-6 border border-slate-800/80 rounded-xl shadow-md"><table class="w-full text-left text-xs border-collapse m-0"><thead class="bg-slate-900/90 text-slate-300 border-b border-slate-800"><tr>';
                    foreach ($cols as $col) {
                        $html .= '<th class="py-3 px-4 font-semibold">' . $this->parse_inline($col) . '</th>';
                    }
                    $html .= '</tr></thead><tbody class="divide-y divide-slate-800/60 bg-slate-950/40">';
                } elseif ($table_header) {
                    $html .= '<tr>';
                    foreach ($cols as $col) {
                        $html .= '<th class="py-3 px-4 font-semibold">' . $this->parse_inline($col) . '</th>';
                    }
                    $html .= '</tr>';
                } else {
                    $html .= '<tr class="hover:bg-slate-900/40 transition-colors">';
                    foreach ($cols as $col) {
                        $html .= '<td class="py-2.5 px-4 text-slate-300">' . $this->parse_inline($col) . '</td>';
                    }
                    $html .= '</tr>';
                }
                continue;
            } else {
                if ($in_table) {
                    $html .= '</tbody></table></div>';
                    $in_table = false;
                }
            }

            // Horizontal Rules
            if (preg_match('/^---/', trim($line))) {
                $html .= '<hr class="border-slate-800/80 my-8">';
                continue;
            }

            // Headings
            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $title = trim($m[2]);
                $clean_title = wp_strip_all_tags($title);
                $slug = sanitize_title($clean_title);

                if ($level === 1) {
                    $html .= '<h1 id="' . esc_attr($slug) . '" class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight mb-4 doc-section">' . $this->parse_inline($title) . '</h1>';
                } elseif ($level === 2) {
                    // Extract Method & Category for TOC
                    $category = 'all';
                    $method = 'API';
                    $match_num = [];

                    if (preg_match('/^(\d+)\.\s*(.*)/', $clean_title, $match_num)) {
                        $num = $match_num[1];
                        $method = $endpoint_method_map[$num] ?? 'API';
                        $category = in_array($num, ['1', '2', '3', '4'], true) ? 'auth' : 'catalog';
                    } elseif (stripos($clean_title, 'typescript') !== false || stripos($clean_title, 'interface') !== false) {
                        $category = 'types';
                        $method = 'TS';
                    } elseif (stripos($clean_title, 'summary') !== false) {
                        $method = 'INDEX';
                    }

                    $toc[] = [
                        'title'    => $clean_title,
                        'slug'     => $slug,
                        'category' => $category,
                        'method'   => $method,
                    ];

                    $html .= '<h2 id="' . esc_attr($slug) . '" class="text-xl sm:text-2xl font-bold text-white tracking-tight mt-12 mb-4 pb-2 border-b border-slate-800 doc-section scroll-mt-24 flex items-center gap-3">';
                    $html .= $this->parse_inline($title);
                    $html .= '</h2>';
                } elseif ($level === 3) {
                    $html .= '<h3 id="' . esc_attr($slug) . '" class="text-base sm:text-lg font-bold text-amber-400 tracking-tight mt-6 mb-3 doc-section scroll-mt-24">' . $this->parse_inline($title) . '</h3>';
                } else {
                    $html .= '<h4 class="text-sm font-semibold text-slate-200 mt-4 mb-2">' . $this->parse_inline($title) . '</h4>';
                }
                continue;
            }

            // Blockquotes
            if (preg_match('/^>\s*(.+)$/', $line, $m)) {
                $html .= '<blockquote class="border-l-4 border-amber-500/60 bg-amber-500/5 px-4 py-3 rounded-r-xl my-4 text-slate-300 text-xs italic">' . $this->parse_inline($m[1]) . '</blockquote>';
                continue;
            }

            // Lists
            if (preg_match('/^[\*\-]\s+(.+)$/', $line, $m)) {
                $html .= '<li class="text-slate-300 text-xs leading-relaxed my-1.5 list-disc ml-5">' . $this->parse_inline($m[1]) . '</li>';
                continue;
            }

            // Empty lines
            if (trim($line) === '') {
                continue;
            }

            // Paragraphs
            $html .= '<p class="text-slate-300 text-xs sm:text-sm leading-relaxed my-3">' . $this->parse_inline($line) . '</p>';
        }

        if ($in_table) {
            $html .= '</tbody></table></div>';
        }

        return [
            'html' => $html,
            'toc'  => $toc,
        ];
    }

    /**
     * Inline Markdown formatting (Code, Bold, Italic, Links, Method Badges).
     */
    private function parse_inline(string $text): string {
        // Escape HTML
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Inline Code `...`
        $text = preg_replace('/`([^`]+)`/', '<code class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-800 text-amber-300 text-[11px] font-mono">$1</code>', $text);

        // Bold **...**
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong class="font-bold text-white">$1</strong>', $text);

        // Italic *...*
        $text = preg_replace('/\*([^*]+)\*/', '<em class="italic text-slate-300">$1</em>', $text);

        // Links [text](url)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" class="text-amber-400 hover:text-amber-300 underline underline-offset-2">$1</a>', $text);

        // Method badges
        $text = preg_replace('/\bGET\b/', '<span class="method-badge method-get">GET</span>', $text);
        $text = preg_replace('/\bPOST\b/', '<span class="method-badge method-post">POST</span>', $text);
        $text = preg_replace('/\bPUT\b/', '<span class="method-badge method-put">PUT</span>', $text);
        $text = preg_replace('/\bDELETE\b/', '<span class="method-badge method-delete">DELETE</span>', $text);

        return $text;
    }

    /**
     * Output standalone documentation page HTML.
     */
    private function render_html(string $htmlContent, array $toc, string $userName, string $userRoles, string $logoutUrl, string $siteName) {
        ?>
<!DOCTYPE html>
<html lang="en" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>REST API Documentation | <?php echo esc_html($siteName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                }
            }
        }
    </script>
    <!-- Prism.js Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-typescript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-http.min.js"></script>

    <style>
        html {
            scroll-behavior: smooth;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        code, pre {
            font-family: 'JetBrains Mono', monospace !important;
        }
        .method-badge {
            display: inline-flex;
            align-items: center;
            font-size: 0.65rem;
            font-weight: 800;
            padding: 0.15rem 0.45rem;
            border-radius: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            line-height: 1;
        }
        .method-get { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .method-post { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
        .method-put { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.3); }
        .method-delete { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .method-types { background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); }
        .method-index { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.3); }
        
        .custom-scrollbar::-webkit-scrollbar { width: 5px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: 9999px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.25); }

        .doc-section {
            scroll-margin-top: 5.5rem;
            transition: all 0.35s ease;
        }
        .doc-section.section-focused {
            color: #fbbf24 !important;
            padding-left: 0.5rem;
            border-left: 3px solid #f59e0b;
        }

        .nav-item-active {
            background-color: rgba(245, 158, 11, 0.15) !important;
            color: #fbbf24 !important;
            font-weight: 700 !important;
            border-left: 3px solid #f59e0b !important;
            padding-left: 0.65rem !important;
        }
    </style>
</head>
<body class="bg-[#080d1a] text-slate-100 min-h-screen antialiased flex flex-col selection:bg-amber-500/30 selection:text-amber-200">

    <!-- Top Navbar -->
    <header class="sticky top-0 z-50 border-b border-slate-800/80 bg-[#080d1a]/95 backdrop-blur-md">
        <div class="max-w-[1680px] mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
            
            <!-- Logo & Brand -->
            <div class="flex items-center gap-3.5">
                <a href="<?php echo esc_url(site_url('/documentation/')); ?>" class="flex items-center gap-2.5 group">
                    <div class="h-9 w-9 rounded-xl bg-gradient-to-tr from-amber-600 to-amber-400 flex items-center justify-center text-slate-950 font-black shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
                        <svg class="w-5 h-5 text-slate-950" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                    </div>
                    <div>
                        <div class="font-bold text-sm text-white tracking-tight flex items-center gap-2">
                            <span>Handicraft in Nepal</span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-amber-500/10 text-amber-400 border border-amber-500/20">REST API v1</span>
                        </div>
                        <div class="text-[11px] text-slate-400">Interactive API & TypeScript Documentation</div>
                    </div>
                </a>
            </div>

            <!-- Search / Quick Filter Bar -->
            <div class="hidden md:flex items-center flex-1 max-w-md mx-4">
                <div class="relative w-full">
                    <input
                        type="text"
                        id="doc-search"
                        placeholder="Search endpoints, parameters, models... (Press / to focus)"
                        class="w-full h-9 pl-9 pr-4 text-xs rounded-xl bg-slate-900/90 border border-slate-800 text-slate-200 placeholder-slate-500 focus:outline-none focus:border-amber-500/50 focus:ring-1 focus:ring-amber-500/50 transition-all"
                    >
                    <svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-500 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 1114 0z" />
                    </svg>
                </div>
            </div>

            <!-- User Auth & Meta Actions -->
            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-xl border border-slate-800 bg-slate-900/80 text-xs">
                    <div class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></div>
                    <span class="text-slate-400">Endpoint:</span>
                    <code class="text-amber-400 font-mono font-medium">/wp-json/handicraft/v1</code>
                </div>

                <div class="flex items-center gap-2.5 pl-2 border-l border-slate-800">
                    <div class="h-8 w-8 rounded-full bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-400 font-bold text-xs uppercase">
                        <?php echo esc_html(strtoupper(substr($userName, 0, 2))); ?>
                    </div>
                    <div class="hidden lg:block text-left">
                        <div class="text-xs font-semibold text-slate-200"><?php echo esc_html($userName); ?></div>
                        <div class="text-[10px] text-slate-400 capitalize"><?php echo esc_html($userRoles); ?></div>
                    </div>
                    <a
                        href="<?php echo esc_url($logoutUrl); ?>"
                        class="p-2 rounded-lg text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition-colors"
                        title="Log out of WordPress"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </a>
                </div>

            </div>

        </div>
    </header>

    <!-- Main Container Layout (Sidebar + Content) -->
    <div class="max-w-[1680px] w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex-1 flex gap-8 items-start">
        
        <!-- Left Sticky Sidebar with Server-Rendered Control Nav & ScrollSpy -->
        <aside class="w-80 shrink-0 hidden lg:flex flex-col sticky top-20 max-h-[calc(100vh-6.5rem)] rounded-2xl border border-slate-800/80 bg-[#0d1424]/80 backdrop-blur-md p-4 shadow-xl">
            
            <!-- Sidebar Header & Category Filter Tabs -->
            <div class="pb-3 border-b border-slate-800 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7" />
                        </svg>
                        API Control Nav
                    </span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-amber-400 font-mono font-semibold">
                        <?php echo count($toc); ?> Topics
                    </span>
                </div>

                <!-- Category Filter Pills -->
                <div class="flex items-center gap-1 bg-slate-900/80 p-1 rounded-xl border border-slate-800 text-[11px] font-medium">
                    <button type="button" data-filter="all" class="filter-tab flex-1 py-1 px-2 rounded-lg bg-amber-500/20 text-amber-300 font-bold transition-all text-center">All</button>
                    <button type="button" data-filter="auth" class="filter-tab flex-1 py-1 px-2 rounded-lg text-slate-400 hover:text-slate-200 transition-all text-center">Auth</button>
                    <button type="button" data-filter="catalog" class="filter-tab flex-1 py-1 px-2 rounded-lg text-slate-400 hover:text-slate-200 transition-all text-center">Catalog</button>
                    <button type="button" data-filter="types" class="filter-tab flex-1 py-1 px-2 rounded-lg text-slate-400 hover:text-slate-200 transition-all text-center">Types</button>
                </div>
            </div>
            
            <!-- Server-Rendered Navigation Items -->
            <nav id="toc-nav" class="flex-1 overflow-y-auto custom-scrollbar pt-3 space-y-1 text-xs pr-1">
                <?php foreach ($toc as $item):
                    $method = $item['method'];
                    $badge_class = 'method-get';
                    if ($method === 'POST') $badge_class = 'method-post';
                    elseif ($method === 'TS') $badge_class = 'method-types';
                    elseif ($method === 'INDEX') $badge_class = 'method-index';
                ?>
                    <a
                        href="#<?php echo esc_attr($item['slug']); ?>"
                        data-target="<?php echo esc_attr($item['slug']); ?>"
                        data-category="<?php echo esc_attr($item['category']); ?>"
                        class="nav-link flex items-center justify-between py-2 px-2.5 rounded-xl text-slate-300 hover:bg-slate-800/80 hover:text-white transition-all text-xs border-l-2 border-transparent"
                    >
                        <div class="flex items-center min-w-0 truncate">
                            <span class="method-badge <?php echo esc_attr($badge_class); ?> mr-2 shrink-0"><?php echo esc_html($method); ?></span>
                            <span class="truncate font-medium"><?php echo esc_html($item['title']); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Quick Environment Info Footer -->
            <div class="pt-3 border-t border-slate-800 mt-2 flex items-center justify-between text-[11px] text-slate-500">
                <span>Click link to jump</span>
                <span class="text-amber-500/80 font-mono">Live Edge API</span>
            </div>
        </aside>

        <!-- Right Content Body -->
        <main class="flex-1 min-w-0 bg-[#0d1424]/40 border border-slate-800/80 rounded-2xl p-6 sm:p-10 shadow-2xl backdrop-blur-xs">
            <div id="markdown-container" class="prose prose-invert prose-slate max-w-none prose-headings:font-bold prose-headings:tracking-tight prose-a:text-amber-400 hover:prose-a:text-amber-300 prose-code:text-amber-300 prose-code:before:content-none prose-code:after:content-none prose-table:overflow-x-auto">
                <?php echo $htmlContent; ?>
            </div>
        </main>

    </div>

    <!-- Footer -->
    <footer class="border-t border-slate-800/60 bg-[#080d1a] py-6 text-center text-xs text-slate-500">
        <p>Handicraft in Nepal REST API Documentation &bull; Live Edge Environment &bull; Authenticated Access Only</p>
    </footer>

    <!-- Interactive ScrollSpy & Navigation Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const navLinks = Array.from(document.querySelectorAll('#toc-nav .nav-link'));
            const docSections = Array.from(document.querySelectorAll('.doc-section'));

            // Helper: Set Active Nav Link
            function setActiveNavLink(activeLink) {
                navLinks.forEach(l => l.classList.remove('nav-item-active'));
                if (activeLink) {
                    activeLink.classList.add('nav-item-active');
                    activeLink.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }

            // Click Handler: Smooth Scroll & Visual Feedback
            navLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const targetId = link.dataset.target;
                    const targetEl = document.getElementById(targetId);

                    if (targetEl) {
                        setActiveNavLink(link);
                        targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });

                        if (history.pushState) {
                            history.pushState(null, null, '#' + targetId);
                        } else {
                            location.hash = '#' + targetId;
                        }

                        docSections.forEach(s => s.classList.remove('section-focused'));
                        targetEl.classList.add('section-focused');
                        setTimeout(() => {
                            targetEl.classList.remove('section-focused');
                        }, 2000);
                    }
                });
            });

            // ScrollSpy using IntersectionObserver
            const observerOptions = {
                root: null,
                rootMargin: '-80px 0px -70% 0px',
                threshold: 0
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const id = entry.target.id;
                        const matchedLink = navLinks.find(l => l.dataset.target === id);
                        if (matchedLink) {
                            setActiveNavLink(matchedLink);
                        }
                    }
                });
            }, observerOptions);

            docSections.forEach(section => observer.observe(section));

            // Initial Active Link on Page Load
            if (location.hash) {
                const initialId = location.hash.replace('#', '');
                const targetLink = navLinks.find(l => l.dataset.target === initialId);
                if (targetLink) {
                    setActiveNavLink(targetLink);
                }
            } else if (navLinks.length > 0) {
                setActiveNavLink(navLinks[0]);
            }

            // Category Filter Tabs
            const filterTabs = document.querySelectorAll('.filter-tab');
            filterTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    filterTabs.forEach(t => {
                        t.classList.remove('bg-amber-500/20', 'text-amber-300', 'font-bold');
                        t.classList.add('text-slate-400');
                    });
                    tab.classList.add('bg-amber-500/20', 'text-amber-300', 'font-bold');
                    tab.classList.remove('text-slate-400');

                    const filter = tab.dataset.filter;
                    navLinks.forEach(link => {
                        if (filter === 'all' || link.dataset.category === filter) {
                            link.style.display = 'flex';
                        } else {
                            link.style.display = 'none';
                        }
                    });
                });
            });

            // Search Filter in Navbar
            const searchInput = document.getElementById('doc-search');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const term = e.target.value.toLowerCase().trim();

                    navLinks.forEach(link => {
                        const match = link.textContent.toLowerCase().includes(term);
                        link.style.display = match ? 'flex' : 'none';
                    });

                    docSections.forEach(h => {
                        const match = h.textContent.toLowerCase().includes(term);
                        h.style.color = match && term ? '#fbbf24' : '';
                    });
                });

                // Press '/' to search
                window.addEventListener('keydown', (e) => {
                    if (e.key === '/' && document.activeElement !== searchInput) {
                        e.preventDefault();
                        searchInput.focus();
                    }
                });
            }
        });
    </script>
</body>
</html>
        <?php
    }
}
