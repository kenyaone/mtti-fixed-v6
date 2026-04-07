<?php
/**
 * MTTI Think Sharp — AI Coach Proxy
 * 
 * Add this code to your mtti-fixed-v5 plugin file
 * OR paste into WordPress functions.php (child theme)
 * 
 * Then go to: WordPress Admin → Settings → MTTI Settings
 * Add your Claude API key in the field provided.
 * 
 * Endpoint: /wp-json/mtti/v1/coach
 */

// ── REGISTER REST ROUTE ──
add_action('rest_api_init', function() {
    register_rest_route('mtti/v1', '/coach', [
        'methods'             => 'POST',
        'callback'            => 'mtti_coach_handler',
        'permission_callback' => '__return_true', // We handle auth ourselves
    ]);
});

/**
 * Main coach handler — validates request, calls Claude API, returns response
 */
function mtti_coach_handler(WP_REST_Request $request) {

    // ── 1. DOMAIN VALIDATION ──
    $origin  = $request->get_header('origin') ?: $request->get_header('referer') ?: '';
    $allowed = 'masomoteletraining.co.ke';
    
    // Allow requests originating from the allowed domain
    if (!empty($origin) && strpos($origin, $allowed) === false) {
        // During development: comment out the return below to test locally
        return new WP_Error('forbidden', 'Unauthorised domain', ['status' => 403]);
    }

    // ── 2. TOKEN VALIDATION ──
    $body  = $request->get_json_params();
    $token = sanitize_text_field($body['token'] ?? '');
    $valid_tokens = ['mttipilot2026', 'MTTIPILOT2026', 'MttiPilot2026'];
    // Phase 2: replace with per-learner token validation against DB
    
    if (!in_array($token, $valid_tokens)) {
        return new WP_Error('unauthorized', 'Invalid access token', ['status' => 401]);
    }

    // ── 3. RATE LIMITING (per session / per day) ──
    $learner_id = sanitize_text_field($body['learner'] ?? 'unknown') . '_ch' . intval($body['chapter'] ?? 0);
    $rate_key   = 'mtti_coach_rate_' . md5($learner_id . date('Y-m-d'));
    $call_count = (int) get_transient($rate_key);
    
    if ($call_count >= 20) {
        return new WP_Error('rate_limited', 'Daily coaching limit reached. Please continue tomorrow.', ['status' => 429]);
    }
    
    set_transient($rate_key, $call_count + 1, DAY_IN_SECONDS);

    // ── 4. GET API KEY ──
    $api_key = get_option('mtti_claude_api_key', '');
    if (empty($api_key)) {
        return new WP_Error('config_error', 'AI coaching not configured. Please contact MTTI.', ['status' => 500]);
    }

    // ── 5. VALIDATE & SANITIZE INPUT ──
    $system   = sanitize_textarea_field($body['system'] ?? '');
    $messages = $body['messages'] ?? [];
    $chapter  = intval($body['chapter'] ?? 0);
    
    if (empty($system) || empty($messages)) {
        return new WP_Error('bad_request', 'Missing required fields', ['status' => 400]);
    }

    // Sanitize messages
    $clean_messages = [];
    foreach ($messages as $msg) {
        $role    = in_array($msg['role'], ['user', 'assistant']) ? $msg['role'] : 'user';
        $content = sanitize_textarea_field($msg['content'] ?? '');
        if (!empty($content)) {
            $clean_messages[] = ['role' => $role, 'content' => $content];
        }
    }

    // Limit conversation history to last 12 messages (6 exchanges)
    if (count($clean_messages) > 12) {
        $clean_messages = array_slice($clean_messages, -12);
    }

    if (empty($clean_messages)) {
        return new WP_Error('bad_request', 'No valid messages provided', ['status' => 400]);
    }

    // ── 6. CALL CLAUDE API ──
    $api_payload = [
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 350,  // Keep responses concise — Socratic coaches are brief
        'system'     => $system,
        'messages'   => $clean_messages,
    ];

    $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
        ],
        'body' => json_encode($api_payload),
    ]);

    // ── 7. HANDLE RESPONSE ──
    if (is_wp_error($response)) {
        error_log('MTTI Coach API error: ' . $response->get_error_message());
        return new WP_Error('api_error', 'Connection to AI coach failed. Please try again.', ['status' => 502]);
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body_raw    = wp_remote_retrieve_body($response);
    $data        = json_decode($body_raw, true);

    if ($status_code !== 200 || empty($data['content'][0]['text'])) {
        error_log('MTTI Coach API bad response: ' . $status_code . ' | ' . $body_raw);
        return new WP_Error('api_error', 'AI coach returned an unexpected response. Please try again.', ['status' => 502]);
    }

    $reply = $data['content'][0]['text'];

    // ── 8. LOG USAGE (optional — for monitoring API costs) ──
    mtti_log_coach_usage($body['learner'] ?? 'unknown', $chapter, $call_count + 1);

    // ── 9. RETURN RESPONSE ──
    return new WP_REST_Response(['reply' => $reply], 200);
}

/**
 * Log coaching usage to WordPress options for cost monitoring
 * View at: WordPress Admin → Tools → MTTI Coach Usage
 */
function mtti_log_coach_usage($learner, $chapter, $count) {
    $log_key  = 'mtti_coach_usage_log';
    $log      = get_option($log_key, []);
    $log[]    = [
        'learner' => $learner,
        'chapter' => $chapter,
        'count'   => $count,
        'date'    => date('Y-m-d H:i:s'),
    ];
    // Keep only last 500 entries
    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }
    update_option($log_key, $log);
}

// ══════════════════════════════════════════════════════
// SETTINGS PAGE — Add Claude API Key in WordPress Admin
// ══════════════════════════════════════════════════════

add_action('admin_menu', function() {
    add_submenu_page(
        'options-general.php',
        'MTTI Think Sharp Settings',
        'MTTI Think Sharp',
        'manage_options',
        'mtti-think-sharp',
        'mtti_think_sharp_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('mtti_think_sharp_group', 'mtti_claude_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ]);
});

function mtti_think_sharp_settings_page() {
    $api_key  = get_option('mtti_claude_api_key', '');
    $log      = get_option('mtti_coach_usage_log', []);
    $today    = array_filter($log, fn($e) => str_starts_with($e['date'], date('Y-m-d')));
    ?>
    <div class="wrap">
        <h1>🎓 MTTI Think Sharp — AI Coach Settings</h1>
        <hr>
        <form method="post" action="options.php">
            <?php settings_fields('mtti_think_sharp_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="mtti_claude_api_key">Claude API Key</label></th>
                    <td>
                        <input type="password" name="mtti_claude_api_key" id="mtti_claude_api_key"
                            value="<?php echo esc_attr($api_key); ?>"
                            class="regular-text"
                            placeholder="sk-ant-api03-...">
                        <p class="description">
                            Get your key from <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.<br>
                            <strong>Never share this key.</strong> It is stored securely in your WordPress database.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Status</th>
                    <td>
                        <?php if (!empty($api_key)): ?>
                            <span style="color:green;font-weight:bold;">✓ API Key configured</span>
                            (key ends in: ...<?php echo substr($api_key, -6); ?>)
                        <?php else: ?>
                            <span style="color:red;font-weight:bold;">✗ No API key set — AI coaching will not work</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Pilot Token</th>
                    <td>
                        <code>mttipilot2026</code>
                        <p class="description">Share this with your pilot learners. URL: <code>masomoteletraining.co.ke/think-sharp?token=mttipilot2026</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Today's Usage</th>
                    <td>
                        <strong><?php echo count($today); ?></strong> AI coaching exchanges today
                        (<?php echo count($log); ?> total logged)
                        <p class="description">Estimated cost today: ~$<?php echo number_format(count($today) * 0.003, 3); ?> USD</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>

        <hr>
        <h2>📊 Recent Coaching Sessions</h2>
        <?php if (!empty($log)): ?>
        <table class="widefat striped" style="max-width:700px;">
            <thead><tr><th>Learner</th><th>Chapter</th><th>Exchange #</th><th>Date/Time</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse(array_slice($log, -20)) as $entry): ?>
                <tr>
                    <td><?php echo esc_html($entry['learner']); ?></td>
                    <td><?php echo intval($entry['chapter']); ?></td>
                    <td><?php echo intval($entry['count']); ?></td>
                    <td><?php echo esc_html($entry['date']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p>No coaching sessions logged yet.</p>
        <?php endif; ?>

        <hr>
        <h2>🧪 Pilot Management</h2>
        <p>Course URL to share with learners:</p>
        <code style="background:#f0f0f0;padding:8px 14px;display:inline-block;border-radius:4px;font-size:1rem;">
            https://masomoteletraining.co.ke/think-sharp?token=mttipilot2026
        </code>
        <p style="margin-top:16px;color:#666;">
            When you're ready to move to paid access, you'll replace the shared token with 
            per-learner tokens generated at payment. This can be added to the existing 
            M-Pesa payment flow in mtti-fixed-v5.
        </p>
    </div>
    <?php
}

// ══════════════════════════════════════════════════════
// CORS HEADERS — Allow requests from the same domain
// ══════════════════════════════════════════════════════
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        $allowed = 'https://masomoteletraining.co.ke';
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === $allowed) {
            header('Access-Control-Allow-Origin: ' . $allowed);
        }
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        return $value;
    });
}, 15);
