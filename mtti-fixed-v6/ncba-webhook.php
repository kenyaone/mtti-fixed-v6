<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * NCBA Paybill Push Notification Endpoint — OPTIMIZED v3
 * 
 * Location: wp-content/plugins/mtti-fixed-v5/ncba-webhook.php
 * 
 * Uses direct MySQLi (no WordPress loaded) for fast response times.
 */

// ── Direct DB connection (no WordPress overhead) ─────────────────────────────
$db = @new mysqli('localhost', 'uvyzhdzt_wp265', 'p!PS(1S17Y', 'uvyzhdzt_wp265');

if ($db->connect_error) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(array('ResultCode' => '1', 'ResultDesc' => 'DB connection failed'));
    exit;
}
$db->set_charset('utf8mb4');

$table_prefix = 'wpcu_';

// ── Constants ────────────────────────────────────────────────────────────────
define('NCBA_USERNAME',   'mtti_ncba_api');
define('NCBA_PASSWORD',   'Mtti@Ncba2024!');
define('NCBA_SECRET_KEY', 'MTTI_SAGAAS_NCBA_K3Y_2024');

// ── Only accept POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── Detect format (JSON or XML) ──────────────────────────────────────────────
$content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
$raw_body     = file_get_contents('php://input');

$is_json = (strpos($content_type, 'application/json') !== false);
$is_xml  = (strpos($content_type, 'application/xml') !== false ||
            strpos($content_type, 'text/xml') !== false);

// ── Parse payload ────────────────────────────────────────────────────────────
$payload = array();

if ($is_json) {
    $payload = json_decode($raw_body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json_response('1', 'Invalid JSON payload');
        exit;
    }
} elseif ($is_xml) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw_body);
    if ($xml === false) {
        send_xml_response('FAIL');
        exit;
    }
    $namespaces = $xml->getNamespaces(true);
    $body = null;
    foreach ($namespaces as $prefix => $ns) {
        $body = $xml->children($ns)->Body;
        if ($body) break;
    }
    if (!$body) {
        $body = $xml->children('http://schemas.xmlsoap.org/soap/envelope/')->Body;
    }
    if ($body) {
        foreach ($body->children() as $request) {
            foreach ($request->children() as $key => $value) {
                $payload[(string)$key] = (string)$value;
            }
        }
    }
} else {
    $payload = json_decode($raw_body, true);
    if (!$payload) {
        send_json_response('1', 'Unsupported Content-Type');
        exit;
    }
    $is_json = true;
}

// ── Extract fields ───────────────────────────────────────────────────────────
$trans_type    = get_field($payload, array('TransType'));
$trans_id      = get_field($payload, array('TransID'));
$ft_ref        = get_field($payload, array('FTRef'));
$trans_time    = get_field($payload, array('TransTime'));
$trans_amount  = get_field($payload, array('TransAmount'));
$short_code    = get_field($payload, array('BusinessShortCode', 'AccountNr'));
$bill_ref      = get_field($payload, array('BillRefNumber', 'Narrative'));
$narrative     = get_field($payload, array('Narrative'));
$mobile        = get_field($payload, array('Mobile', 'PhoneNr'));
$customer_name = get_field($payload, array('name', 'CustomerName', 'Name'));
$username      = get_field($payload, array('Username', 'User'));
$password      = get_field($payload, array('Password'));
$hash          = get_field($payload, array('Hash', 'HashVal', 'SecretKey'));

// ── 1. Authenticate credentials ──────────────────────────────────────────────
if ($username !== NCBA_USERNAME || $password !== NCBA_PASSWORD) {
    log_ncba('AUTH_FAIL', $trans_id, $trans_amount, $bill_ref, 'Bad username/password');
    if ($is_json) { send_json_response('1', 'Authentication failed'); }
    else { send_xml_response('FAIL'); }
    $db->close();
    exit;
}

// ── 2. Verify hash (log mismatch but still process) ─────────────────────────
$expected_hash = generate_hash(
    NCBA_SECRET_KEY,
    $trans_type, $trans_id, $trans_time,
    $trans_amount, $short_code, $bill_ref,
    $mobile, $customer_name
);

if ($hash !== $expected_hash) {
    log_ncba('HASH_MISMATCH', $trans_id, $trans_amount, $bill_ref,
             "Expected: $expected_hash | Got: $hash");
}

// ── 3. Save payment (INSERT IGNORE handles duplicates) ──────────────────────
$admission_number = strtoupper(trim($bill_ref));
$received_at      = date('Y-m-d H:i:s');
$ncba_table       = $table_prefix . 'ncba_payments';
$format           = $is_json ? 'JSON' : 'XML';
$amt              = floatval($trans_amount);

$stmt = $db->prepare("INSERT IGNORE INTO `$ncba_table`
    (trans_id, ft_ref, trans_type, trans_time, trans_amount, short_code,
     bill_ref, narrative, mobile, customer_name, student_id, admission_number,
     format, raw_payload, received_at, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, 'received')");

if (!$stmt) {
    log_ncba('DB_FAIL', $trans_id, $trans_amount, $bill_ref, 'Prepare failed: ' . $db->error);
    if ($is_json) { send_json_response('1', 'Internal server error'); }
    else { send_xml_response('FAIL'); }
    $db->close();
    exit;
}

$stmt->bind_param('ssssdsssssssss',
    $trans_id, $ft_ref, $trans_type, $trans_time, $amt, $short_code,
    $bill_ref, $narrative, $mobile, $customer_name, $admission_number,
    $format, $raw_body, $received_at
);

$stmt->execute();

if ($stmt->error) {
    log_ncba('DB_FAIL', $trans_id, $trans_amount, $bill_ref, 'Execute failed: ' . $stmt->error);
    if ($is_json) { send_json_response('1', 'Internal server error'); }
    else { send_xml_response('FAIL'); }
    $stmt->close();
    $db->close();
    exit;
}

// Check duplicate (INSERT IGNORE returns affected_rows = 0 for dupes)
if ($stmt->affected_rows === 0) {
    log_ncba('DUPLICATE', $trans_id, $trans_amount, $bill_ref, 'Already processed');
    if ($is_json) { send_json_response('0', 'Duplicate - already processed'); }
    else { send_xml_response('OK'); }
    $stmt->close();
    $db->close();
    exit;
}
$stmt->close();

// ── 4. Find student + enrollment and post payment ───────────────────────────
$students_table = $table_prefix . 'mtti_students';
$enroll_table   = $table_prefix . 'mtti_enrollments';
$payments_table = $table_prefix . 'mtti_payments';

$find_stmt = $db->prepare("SELECT s.student_id, e.enrollment_id
    FROM `$students_table` s
    LEFT JOIN `$enroll_table` e ON e.student_id = s.student_id AND e.status IN ('Active','Enrolled','In Progress')
    WHERE s.admission_number = ?
    ORDER BY e.enrollment_id DESC LIMIT 1");

if ($find_stmt) {
    $find_stmt->bind_param('s', $admission_number);
    $find_stmt->execute();
    $result = $find_stmt->get_result();
    $row = $result ? $result->fetch_object() : null;
    $find_stmt->close();

    if ($row) {
        // Update ncba_payments with student_id
        $up = $db->prepare("UPDATE `$ncba_table` SET student_id = ? WHERE trans_id = ?");
        if ($up) {
            $sid = intval($row->student_id);
            $up->bind_param('is', $sid, $trans_id);
            $up->execute();
            $up->close();
        }

        // Post payment to MIS if active enrollment found
        if ($row->enrollment_id) {
            $pay_stmt = $db->prepare("INSERT INTO `$payments_table`
                (student_id, enrollment_id, amount, payment_method, transaction_reference, notes, payment_date, recorded_by)
                VALUES (?, ?, ?, 'MPESA', ?, ?, ?, 0)");
            if ($pay_stmt) {
                $sid = intval($row->student_id);
                $eid = intval($row->enrollment_id);
                $notes = "NCBA Push | BillRef: " . $bill_ref;
                $pay_stmt->bind_param('iidsss',
                    $sid, $eid, $amt,
                    $trans_id, $notes, $received_at
                );
                $pay_stmt->execute();
                $pay_stmt->close();
            }
        }
    }
}

// ── 5. Respond success ───────────────────────────────────────────────────────
log_ncba('SUCCESS', $trans_id, $trans_amount, $bill_ref,
         "Payment KES $trans_amount from $customer_name");
if ($is_json) { send_json_response('0', 'Payment received successfully'); }
else { send_xml_response('OK'); }

$db->close();
exit;


// ═══════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

function get_field($payload, $keys, $default = '') {
    foreach ($keys as $key) {
        if (isset($payload[$key]) && $payload[$key] !== '') {
            return trim($payload[$key]);
        }
    }
    return $default;
}

function generate_hash($secret_key, $trans_type, $trans_id, $trans_time,
                        $trans_amount, $short_code, $bill_ref, $mobile, $name) {
    $string = $secret_key . $trans_type . $trans_id . $trans_time .
              $trans_amount . $short_code . $bill_ref . $mobile . $name . '1';
    $sha256 = hash('sha256', $string);
    return base64_encode($sha256);
}

function log_ncba($type, $trans_id, $amount, $bill_ref, $message = '') {
    $log_dir = dirname(dirname(dirname(__DIR__))) . '/ncba-logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
        @file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
    }
    $log_file = $log_dir . '/ncba-' . date('Y-m') . '.log';
    $entry = sprintf("[%s] [%s] TransID=%s Amount=%s BillRef=%s | %s\n",
        date('Y-m-d H:i:s'), $type, $trans_id ? $trans_id : '-', $amount ? $amount : '-',
        $bill_ref ? $bill_ref : '-', $message);
    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function send_json_response($result_code, $result_desc) {
    header('Content-Type: application/json');
    echo json_encode(array(
        'ResultCode' => $result_code,
        'ResultDesc' => $result_desc,
    ));
}

function send_xml_response($result) {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">';
    echo '<soapenv:Header/><soapenv:Body>';
    echo '<NCBAPaymentNotificationResult>';
    echo '<r>' . htmlspecialchars($result) . '</r>';
    echo '</NCBAPaymentNotificationResult>';
    echo '</soapenv:Body></soapenv:Envelope>';
}
