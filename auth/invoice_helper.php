<?php
/**
 * Invoice Serial Number Helper
 * * Generates independent serial number sequences for Cash and Online payments.
 * Cash payments get: CASH-YYYY-0001, CASH-YYYY-0002, ...
 * Online payments get: ONL-YYYY-0001, ONL-YYYY-0002, ...
 * Each mode maintains its own counter independently.
 */

/**
 * Get mode-specific invoice serial number
 */
function getInvoiceSerialNumber($conn, $payment_id, $payment_mode, $date_str = null)
{
    // Clean string and determine prefix
    $pay_mode = strtolower(trim($payment_mode ?? 'cash'));
    $is_cash = ($pay_mode === 'cash');
    $prefix = $is_cash ? 'CASH' : 'ONL';

    // Determine year from date or use current year
    $year = $date_str ? date("Y", strtotime($date_str)) : date("Y");

    // Enforce connection scoping
    if ($conn === null) {
        global $conn; // Try grabbing the global connection explicitly
        if (!$conn && isset($GLOBALS['conn'])) {
            $conn = $GLOBALS['conn'];
        } 
        if (!$conn) {
            $db_path = __DIR__ . '/db.php';
            if(file_exists($db_path)) {
                require $db_path;
            }
        }
        if (!$conn) {
            // Absolute Fallback: return payment_id based serial
            return $prefix . "-" . $year . "-" . str_pad($payment_id, 4, '0', STR_PAD_LEFT);
        }
    }

    // Count how many payments of the SAME category exist with payment_id <= current
    if ($is_cash) {
        $sql = "SELECT COUNT(*) as seq FROM member_payments 
                WHERE payment_id <= ? AND LOWER(TRIM(payment_mode)) = 'cash'";
    } else {
        $sql = "SELECT COUNT(*) as seq FROM member_payments 
                WHERE payment_id <= ? AND LOWER(TRIM(payment_mode)) != 'cash'";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $seq = $result['seq'] ?? 1;
    $stmt->close();

    return $prefix . "-" . $year . "-" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Get mode-specific installment receipt serial number
 */
function getInstallmentSerialNumber($conn, $installment_id, $payment_mode, $date_str = null)
{
    $pay_mode = strtolower(trim($payment_mode ?? 'cash'));
    $is_cash = ($pay_mode === 'cash');
    $prefix = $is_cash ? 'CASH' : 'ONL';

    $year = $date_str ? date("Y", strtotime($date_str)) : date("Y");

    if ($conn === null) {
        global $conn;
        if (!$conn && isset($GLOBALS['conn'])) {
            $conn = $GLOBALS['conn'];
        } 
        if (!$conn) {
            $db_path = __DIR__ . '/db.php';
            if(file_exists($db_path)) {
                require $db_path;
            }
        }
        if (!$conn) {
            return $prefix . "-" . $year . "-INST-" . str_pad($installment_id, 3, '0', STR_PAD_LEFT);
        }
    }

    if ($is_cash) {
        $sql = "SELECT COUNT(*) as seq FROM installment_payments 
                WHERE id <= ? AND LOWER(TRIM(payment_mode)) = 'cash'";
    } else {
        $sql = "SELECT COUNT(*) as seq FROM installment_payments 
                WHERE id <= ? AND LOWER(TRIM(payment_mode)) != 'cash'";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $installment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $seq = $result['seq'] ?? 1;
    $stmt->close();

    return $prefix . "-" . $year . "-INST-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
}
?>