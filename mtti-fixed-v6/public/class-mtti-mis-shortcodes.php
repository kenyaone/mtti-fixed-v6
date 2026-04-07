<?php
class MTTI_MIS_Shortcodes {
    
    public function __construct() {
        // Register shortcodes immediately on construct as backup
        add_shortcode('mtti_verify_certificate', array($this, 'verify_certificate_shortcode'));
    }
    
    public function register_shortcodes() {
        // Register shortcodes on init hook
        add_shortcode('mtti_verify_certificate', array($this, 'verify_certificate_shortcode'));
        add_shortcode('mtti_courses', array($this, 'courses_shortcode'));
        // mtti_student_portal / mtti_learner_portal registered by Learner Portal class (init)
        // mtti_lecturer_portal registered by Lecturer Portal class (init)
    }
    
    public function courses_shortcode($atts) {
        return '<div class="mtti-courses">Course catalog coming soon...</div>';
    }
    
    /**
     * Certificate Verification Shortcode
     * Usage: [mtti_verify_certificate]
     */
    public function verify_certificate_shortcode($atts) {
        global $wpdb;
        
        $search_term = '';
        $certificate = null;
        $searched = false;
        $debug_info = '';
        
        // Check for code parameter (from form or QR code)
        if (isset($_GET['code']) && !empty($_GET['code'])) {
            $search_term = sanitize_text_field($_GET['code']);
            $searched = true;
        }
        // Also check POST as fallback
        elseif (isset($_POST['search_term']) && !empty($_POST['search_term'])) {
            $search_term = sanitize_text_field($_POST['search_term']);
            $searched = true;
        }
        
        // Search for certificate
        if ($searched && !empty($search_term)) {
            // Get the correct table name using WordPress prefix
            $table = $wpdb->prefix . 'mtti_certificates';
            
            // Debug: Show table name being searched
            $debug_info = "<!-- Debug: Searching table: {$table} for: {$search_term} -->";
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            $debug_info .= "<!-- Table exists: " . ($table_exists ? 'YES' : 'NO') . " -->";
            
            if ($table_exists) {
                // Search for the certificate
                $certificate = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} 
                     WHERE certificate_number = %s 
                     OR verification_code = %s 
                     LIMIT 1",
                    $search_term, $search_term
                ));
                
                $debug_info .= "<!-- Certificate found: " . ($certificate ? 'YES' : 'NO') . " -->";
                $debug_info .= "<!-- Last SQL error: " . $wpdb->last_error . " -->";
                
                // If certificate found but no status column or empty, assume Valid
                if ($certificate) {
                    if (!property_exists($certificate, 'status') || empty($certificate->status)) {
                        $certificate->status = 'Valid';
                    }
                }
            }
        }
        
        ob_start();
        echo $debug_info; // Output debug info as HTML comments
        ?>
        <style>
            .mtti-verify-container {
                max-width: 700px;
                margin: 0 auto;
                padding: 20px;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            .mtti-verify-card {
                background: #fff;
                border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08);
                padding: 40px;
                margin-bottom: 30px;
            }
            .mtti-verify-header {
                text-align: center;
                margin-bottom: 30px;
            }
            .mtti-verify-icon {
                font-size: 60px;
                margin-bottom: 15px;
            }
            .mtti-verify-title {
                font-size: 28px;
                color: #1e3c72;
                margin-bottom: 10px;
                font-weight: 700;
            }
            .mtti-verify-subtitle {
                font-size: 16px;
                color: #666;
            }
            .mtti-verify-form {
                margin: 30px 0;
            }
            .mtti-verify-label {
                display: block;
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin-bottom: 12px;
            }
            .mtti-verify-input {
                width: 100%;
                padding: 16px 20px;
                font-size: 18px;
                border: 2px solid #e0e0e0;
                border-radius: 12px;
                font-family: 'Courier New', monospace;
                letter-spacing: 1px;
                text-transform: uppercase;
                transition: all 0.3s;
                margin-bottom: 20px;
                box-sizing: border-box;
            }
            .mtti-verify-input:focus {
                outline: none;
                border-color: #2a5298;
                box-shadow: 0 0 0 4px rgba(42, 82, 152, 0.1);
            }
            .mtti-verify-btn {
                width: 100%;
                padding: 18px;
                background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%);
                color: white !important;
                border: none;
                border-radius: 12px;
                font-size: 18px;
                font-weight: 600;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .mtti-verify-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(42, 82, 152, 0.3);
                color: white !important;
            }
            
            /* Result Styles */
            .mtti-result {
                margin-top: 30px;
                padding: 30px;
                border-radius: 12px;
            }
            .mtti-result.valid {
                background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
                border: 2px solid #4CAF50;
            }
            .mtti-result.invalid {
                background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
                border: 2px solid #f44336;
            }
            .mtti-result.revoked {
                background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
                border: 2px solid #FF9800;
            }
            .mtti-result-header {
                display: flex;
                align-items: center;
                gap: 15px;
                margin-bottom: 20px;
            }
            .mtti-result-icon {
                font-size: 48px;
            }
            .mtti-result-title {
                font-size: 24px;
                font-weight: 700;
                margin: 0;
            }
            .mtti-result.valid .mtti-result-title { color: #2E7D32; }
            .mtti-result.invalid .mtti-result-title { color: #c62828; }
            .mtti-result.revoked .mtti-result-title { color: #E65100; }
            
            .mtti-result-desc {
                font-size: 16px;
                color: #555;
                margin-bottom: 25px;
                line-height: 1.6;
            }
            .mtti-cert-details {
                background: rgba(255,255,255,0.7);
                border-radius: 10px;
                padding: 20px;
            }
            .mtti-detail-row {
                display: flex;
                padding: 12px 0;
                border-bottom: 1px solid rgba(0,0,0,0.08);
            }
            .mtti-detail-row:last-child {
                border-bottom: none;
            }
            .mtti-detail-label {
                width: 180px;
                font-weight: 600;
                color: #555;
                flex-shrink: 0;
            }
            .mtti-detail-value {
                flex: 1;
                color: #333;
            }
            .mtti-status-badge {
                display: inline-block;
                padding: 6px 16px;
                border-radius: 20px;
                font-size: 14px;
                font-weight: 700;
                letter-spacing: 1px;
            }
            .mtti-status-badge.valid {
                background: #4CAF50;
                color: white;
            }
            .mtti-status-badge.revoked {
                background: #FF9800;
                color: white;
            }
            
            .mtti-help-box {
                background: #f8f9fa;
                border-radius: 12px;
                padding: 25px;
                border-left: 4px solid #2a5298;
                margin-top: 20px;
            }
            .mtti-help-box strong {
                color: #1e3c72;
                font-size: 16px;
            }
            .mtti-help-box ul {
                margin: 15px 0 0 20px;
                line-height: 1.8;
            }
            
            @media (max-width: 600px) {
                .mtti-verify-card { padding: 25px; }
                .mtti-detail-row { flex-direction: column; }
                .mtti-detail-label { width: 100%; margin-bottom: 5px; }
            }
        </style>
        
        <div class="mtti-verify-container">
            <div class="mtti-verify-card">
                <div class="mtti-verify-header">
                    <div class="mtti-verify-icon">🎓</div>
                    <h2 class="mtti-verify-title">Certificate Verification</h2>
                    <p class="mtti-verify-subtitle">Verify the authenticity of MTTI certificates</p>
                </div>
                
                <form method="GET" class="mtti-verify-form" action="">
                    <label for="mtti_search_term" class="mtti-verify-label">
                        Enter Certificate Number or Verification Code
                    </label>
                    <input 
                        type="text" 
                        name="code" 
                        id="mtti_search_term"
                        class="mtti-verify-input"
                        placeholder="e.g., MTTI/CERT/2025/123456"
                        value="<?php echo esc_attr($search_term); ?>"
                        required
                    >
                    <button type="submit" class="mtti-verify-btn">
                        🔍 Verify Certificate
                    </button>
                </form>
                
                    <?php if ($searched): ?>
                    <?php if ($certificate && strtolower($certificate->status) !== 'revoked'): ?>
                        <div class="mtti-result valid">
                            <div class="mtti-result-header">
                                <div class="mtti-result-icon">✅</div>
                                <h3 class="mtti-result-title">Certificate Valid</h3>
                            </div>
                            <p class="mtti-result-desc">
                                This is an authentic certificate issued by Masomotele Technical Training Institute.
                            </p>
                            <div class="mtti-cert-details">
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Certificate No:</div>
                                    <div class="mtti-detail-value"><strong><?php echo esc_html($certificate->certificate_number); ?></strong></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Student Name:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->student_name); ?></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Admission No:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->admission_number); ?></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Course:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->course_name); ?> (<?php echo esc_html($certificate->course_code); ?>)</div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Grade:</div>
                                    <div class="mtti-detail-value"><strong><?php echo esc_html($certificate->grade); ?></strong></div>
                                </div>
                                <?php if (!empty($certificate->completion_date)): ?>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Completion Date:</div>
                                    <div class="mtti-detail-value"><?php echo date('F j, Y', strtotime($certificate->completion_date)); ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Issue Date:</div>
                                    <div class="mtti-detail-value"><?php echo date('F j, Y', strtotime($certificate->issue_date)); ?></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Verification Code:</div>
                                    <div class="mtti-detail-value" style="font-family: monospace; letter-spacing: 2px;"><?php echo esc_html($certificate->verification_code); ?></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Status:</div>
                                    <div class="mtti-detail-value"><span class="mtti-status-badge valid">✓ VALID</span></div>
                                </div>
                            </div>
                        </div>
                        
                    <?php elseif ($certificate && strtolower($certificate->status) === 'revoked'): ?>
                        <div class="mtti-result revoked">
                            <div class="mtti-result-header">
                                <div class="mtti-result-icon">⚠️</div>
                                <h3 class="mtti-result-title">Certificate Revoked</h3>
                            </div>
                            <p class="mtti-result-desc">
                                This certificate has been revoked and is no longer valid.
                            </p>
                            <div class="mtti-cert-details">
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Certificate No:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->certificate_number); ?></div>
                                </div>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Student Name:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->student_name); ?></div>
                                </div>
                                <?php if ($certificate->notes): ?>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Reason:</div>
                                    <div class="mtti-detail-value"><?php echo esc_html($certificate->notes); ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="mtti-detail-row">
                                    <div class="mtti-detail-label">Status:</div>
                                    <div class="mtti-detail-value"><span class="mtti-status-badge revoked">⚠ REVOKED</span></div>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <div class="mtti-result invalid">
                            <div class="mtti-result-header">
                                <div class="mtti-result-icon">❌</div>
                                <h3 class="mtti-result-title">Certificate Not Found</h3>
                            </div>
                            <p class="mtti-result-desc">
                                The certificate number or verification code "<strong><?php echo esc_html($search_term); ?></strong>" could not be found in our records.
                            </p>
                            <div class="mtti-cert-details">
                                <p><strong>This could mean:</strong></p>
                                <ul style="margin: 10px 0 0 20px; line-height: 1.8;">
                                    <li>The certificate number was entered incorrectly</li>
                                    <li>The certificate was not issued by MTTI</li>
                                    <li>The certificate may be fraudulent</li>
                                </ul>
                                <p style="margin-top: 15px;">Please double-check and try again, or contact MTTI for assistance.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="mtti-help-box">
                    <strong>📌 How to Verify</strong>
                    <p style="margin: 10px 0;">You can verify any MTTI certificate using:</p>
                    <ul>
                        <li><strong>Certificate Number</strong> - Found at bottom of certificate (e.g., MTTI/CERT/2025/123456)</li>
                        <li><strong>Verification Code</strong> - Found on certificate (e.g., ABCD-EFGH-JKLM)</li>
                        <li><strong>QR Code</strong> - Scan with your phone for instant verification</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
