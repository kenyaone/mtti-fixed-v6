<?php
/**
 * MTTI Certificate Verification - Standalone Page
 * 
 * Upload this to: /wp-content/plugins/mtti-simple/verify-certificate-custom.php
 * Access via: https://yoursite.com/wp-content/plugins/mtti-simple/verify-certificate-custom.php
 */

// Load WordPress
$wp_load_paths = array(
    dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php',  // Standard: go up 3 levels
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',                      // Document root
);

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if ($path && file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

// Get the search term
$search_term = '';
$certificate = null;
$searched = false;

if (isset($_GET['code']) && !empty($_GET['code'])) {
    $search_term = sanitize_text_field($_GET['code']);
    $searched = true;
}

// Search for certificate if WordPress loaded
if ($wp_loaded && $searched && !empty($search_term)) {
    global $wpdb;
    $table = $wpdb->prefix . 'mtti_certificates';
    
    $certificate = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} 
         WHERE certificate_number = %s 
         OR verification_code = %s 
         LIMIT 1",
        $search_term, $search_term
    ));
    
    // Set default status if not present or empty
    if ($certificate) {
        if (!property_exists($certificate, 'status') || empty($certificate->status)) {
            $certificate->status = 'Valid';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Certificate - MTTI</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; }
        
        .header { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); padding: 20px 40px; color: white; }
        .header h1 { font-size: 24px; margin: 0; }
        
        .main { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        
        .card { background: white; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 40px; margin-bottom: 30px; }
        .card-title { text-align: center; font-size: 28px; color: #1e3c72; margin-bottom: 10px; }
        .card-subtitle { text-align: center; font-size: 16px; color: #666; margin-bottom: 30px; }
        
        .form-label { display: block; font-size: 16px; font-weight: 600; color: #333; margin-bottom: 12px; }
        .form-input { width: 100%; padding: 16px 20px; font-size: 18px; border: 2px solid #e0e0e0; border-radius: 12px; font-family: monospace; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 20px; }
        .form-input:focus { outline: none; border-color: #2a5298; }
        
        .btn { width: 100%; padding: 18px; background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%); color: white; border: none; border-radius: 12px; font-size: 18px; font-weight: 600; cursor: pointer; }
        .btn:hover { opacity: 0.9; }
        
        .result { margin-top: 30px; padding: 30px; border-radius: 12px; }
        .result.valid { background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%); border: 2px solid #4CAF50; }
        .result.invalid { background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%); border: 2px solid #f44336; }
        .result.revoked { background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%); border: 2px solid #FF9800; }
        
        .result-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .result-icon { font-size: 48px; }
        .result-title { font-size: 24px; font-weight: 700; }
        .result.valid .result-title { color: #2E7D32; }
        .result.invalid .result-title { color: #c62828; }
        
        .details { background: rgba(255,255,255,0.7); border-radius: 10px; padding: 20px; }
        .detail-row { display: flex; padding: 12px 0; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { width: 180px; font-weight: 600; color: #555; }
        .detail-value { flex: 1; color: #333; }
        
        .badge { display: inline-block; padding: 6px 16px; border-radius: 20px; font-size: 14px; font-weight: 700; }
        .badge.valid { background: #4CAF50; color: white; }
        .badge.revoked { background: #FF9800; color: white; }
        
        .help { background: #f8f9fa; border-radius: 12px; padding: 25px; border-left: 4px solid #2a5298; margin-top: 30px; }
        .help ul { margin: 15px 0 0 20px; line-height: 1.8; }
        
        .footer { background: #1e3c72; color: white; padding: 30px; text-align: center; margin-top: 60px; }
        
        .debug { background: #fffde7; border: 1px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 8px; font-family: monospace; font-size: 12px; }
        
        @media (max-width: 600px) {
            .card { padding: 25px; }
            .detail-row { flex-direction: column; }
            .detail-label { width: 100%; margin-bottom: 5px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎓 Masomotele Technical Training Institute</h1>
    </div>
    
    <div class="main">
        <div class="card">
            <h2 class="card-title">Certificate Verification</h2>
            <p class="card-subtitle">Verify the authenticity of MTTI certificates</p>
            
            <form method="GET" action="">
                <label class="form-label">Enter Certificate Number or Verification Code</label>
                <input type="text" name="code" class="form-input" 
                       placeholder="e.g., MTTI/CERT/2025/123456" 
                       value="<?php echo esc_attr($search_term); ?>" required>
                <button type="submit" class="btn">🔍 Verify Certificate</button>
            </form>
            
            <?php if (!$wp_loaded): ?>
                <div class="result invalid">
                    <div class="result-header">
                        <div class="result-icon">⚠️</div>
                        <h3 class="result-title">Configuration Error</h3>
                    </div>
                    <p>Could not load WordPress. Please use the main verification page at <a href="/verify-certificate/">/verify-certificate/</a></p>
                </div>
            <?php elseif ($searched): ?>
                
                <?php /* Debug Info - Hidden in production
                <div class="debug">
                    <strong>Debug Info:</strong><br>
                    Table: <?php echo $wpdb->prefix; ?>mtti_certificates<br>
                    Search: <?php echo esc_html($search_term); ?><br>
                    Found: <?php echo $certificate ? 'YES' : 'NO'; ?><br>
                    Status: <?php echo $certificate ? esc_html($certificate->status) : 'N/A'; ?><br>
                    <?php if ($wpdb->last_error): ?>
                    Error: <?php echo esc_html($wpdb->last_error); ?>
                    <?php endif; ?>
                </div>
                */ ?>
                
                <?php if ($certificate && strtolower($certificate->status) !== 'revoked'): ?>
                    <div class="result valid">
                        <div class="result-header">
                            <div class="result-icon">✅</div>
                            <h3 class="result-title">Certificate Valid</h3>
                        </div>
                        <p>This is an authentic certificate issued by MTTI.</p>
                        
                        <div class="details">
                            <div class="detail-row">
                                <div class="detail-label">Certificate #:</div>
                                <div class="detail-value"><strong><?php echo esc_html($certificate->certificate_number); ?></strong></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Student Name:</div>
                                <div class="detail-value"><?php echo esc_html($certificate->student_name); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Admission #:</div>
                                <div class="detail-value"><?php echo esc_html($certificate->admission_number); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Course:</div>
                                <div class="detail-value"><?php echo esc_html($certificate->course_name); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Grade:</div>
                                <div class="detail-value"><strong><?php echo esc_html($certificate->grade); ?></strong></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Issue Date:</div>
                                <div class="detail-value"><?php echo date('F j, Y', strtotime($certificate->issue_date)); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Verification Code:</div>
                                <div class="detail-value" style="font-family: monospace;"><?php echo esc_html($certificate->verification_code); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Status:</div>
                                <div class="detail-value"><span class="badge valid">✓ VALID</span></div>
                            </div>
                        </div>
                    </div>
                    
                <?php elseif ($certificate && strtolower($certificate->status) === 'revoked'): ?>
                    <div class="result revoked">
                        <div class="result-header">
                            <div class="result-icon">⚠️</div>
                            <h3 class="result-title">Certificate Revoked</h3>
                        </div>
                        <p>This certificate has been revoked and is no longer valid.</p>
                        <div class="details">
                            <div class="detail-row">
                                <div class="detail-label">Certificate #:</div>
                                <div class="detail-value"><?php echo esc_html($certificate->certificate_number); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Status:</div>
                                <div class="detail-value"><span class="badge revoked">⚠ REVOKED</span></div>
                            </div>
                        </div>
                    </div>
                    
                <?php else: ?>
                    <div class="result invalid">
                        <div class="result-header">
                            <div class="result-icon">❌</div>
                            <h3 class="result-title">Certificate Not Found</h3>
                        </div>
                        <p>The certificate "<strong><?php echo esc_html($search_term); ?></strong>" could not be found.</p>
                        <div class="details">
                            <p><strong>This could mean:</strong></p>
                            <ul style="margin: 10px 0 0 20px;">
                                <li>The certificate number was entered incorrectly</li>
                                <li>The certificate was not issued by MTTI</li>
                                <li>The certificate may be fraudulent</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
            
            <div class="help">
                <strong>📌 How to Verify</strong>
                <ul>
                    <li><strong>Certificate Number</strong> - e.g., MTTI/CERT/2025/123456</li>
                    <li><strong>Verification Code</strong> - e.g., ABCD-EFGH-JKLM</li>
                    <li><strong>QR Code</strong> - Scan with your phone camera</li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <strong>Masomotele Technical Training Institute</strong><br>
        Sagaas Center, Fourth Floor, Eldoret, Kenya<br>
        "Start Learning, Start Earning"
    </div>
</body>
</html>
