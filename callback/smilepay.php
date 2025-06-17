<?php
/**
 * SmilePay 付款完成通知處理
 * 處理 SmilePay 回傳的付款狀態 (支援 GET 和 POST)
 */

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// 記錄原始請求
$requestMethod = $_SERVER['REQUEST_METHOD'];
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// 根據請求方式取得資料
if ($requestMethod === 'GET') {
    $callbackData = $_GET;
    logActivity('SmilePay Callback - GET Data: ' . print_r($_GET, true));
    logActivity('SmilePay Callback - Query String: ' . $queryString);
} else {
    $callbackData = $_POST;
    logActivity('SmilePay Callback - POST Data: ' . print_r($_POST, true));
}

// 記錄請求資訊
logActivity('SmilePay Callback - Method: ' . $requestMethod);

// 檢查是否有資料
if (empty($callbackData)) {
    logActivity('SmilePay Callback Error - No callback data received');
    http_response_code(400);
    echo '<Roturlstatus>ERROR</Roturlstatus>';
    exit;
}

try {
    // 檢查必要參數
    $requiredFields = ['Od_sob', 'Amount', 'Response_id', 'Smseid'];
    foreach ($requiredFields as $field) {
        if (!isset($callbackData[$field])) {
            throw new Exception('Missing required field: ' . $field);
        }
    }
    
    // 解析回傳資料
    $odSob = $callbackData['Od_sob'];              // 消費項目（原本設定的發票ID）
    $dataId = $callbackData['Data_id'] ?? '';      // 訂單號碼（可能為空）
    $amount = $callbackData['Amount'];             // 實際成交金額
    $purchAmount = $callbackData['Purchamt'] ?? $amount;  // 交易金額
    $responseId = $callbackData['Response_id'];    // 授權結果 (1=成功, 0=失敗)
    $smseid = $callbackData['Smseid'];            // SmilePay追蹤碼
    $paymentNo = $callbackData['Payment_no'] ?? ''; // 交易號碼
    $classif = $callbackData['Classif'] ?? '';    // 付費方式
    $processDate = $callbackData['Process_date'] ?? '';
    $processTime = $callbackData['Process_time'] ?? '';
    $authCode = $callbackData['Auth_code'] ?? '';
    $midSmilepay = $callbackData['Mid_smilepay'] ?? '';  // SmilePay 驗證碼
    $errDesc = $callbackData['Errdesc'] ?? '';
    $fee = $callbackData['Fee'] ?? 0;
    
    // 確定發票ID - 優先使用 Od_sob，如果沒有則用 Data_id
    $invoiceId = !empty($odSob) ? $odSob : $dataId;
    
    if (empty($invoiceId)) {
        throw new Exception('無法確定發票ID - Od_sob 和 Data_id 都為空');
    }
    
    // 記錄關鍵資訊
    logActivity('SmilePay Callback Processing - Invoice: ' . $invoiceId . ', Amount: ' . $amount . ', Response: ' . $responseId . ', Smseid: ' . $smseid . ', Payment Method: ' . getPaymentMethodName($classif));
    
    // 驗證發票是否存在
    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if ($invoice['result'] !== 'success') {
        throw new Exception('Invoice not found: ' . $invoiceId);
    }
    
    // 檢查發票金額（允許小數點誤差）
    $invoiceAmount = floatval($invoice['total']);
    $callbackAmount = floatval($amount);
    if (abs($invoiceAmount - $callbackAmount) > 0.01) {
        logActivity('SmilePay Amount Mismatch - Expected: ' . $invoiceAmount . ', Received: ' . $callbackAmount);
        // 記錄但不阻止處理，因為可能有手續費差異
    }
    
    // 獲取 SmilePay 閘道設定
    $gatewayParams = getGatewayVariables('smilepay');
    if (!$gatewayParams['type']) {
        throw new Exception('SmilePay gateway not found or not active');
    }
    
    // 驗證 SmilePay 驗證碼（如果有設定商家驗證參數）
    $verifyParams = '0000'; // 預設的商家驗證參數
    if (!empty($verifyParams) && $verifyParams !== '0000' && !empty($midSmilepay)) {
        $calculatedVerifyCode = calculateSmilePayVerifyCode($verifyParams, $purchAmount, $smseid);
        if ($calculatedVerifyCode != $midSmilepay) {
            logActivity('SmilePay Verify Code Mismatch - Expected: ' . $calculatedVerifyCode . ', Received: ' . $midSmilepay);
            // 記錄但不拋出異常，繼續處理
        } else {
            logActivity('SmilePay Verify Code Check Passed');
        }
    } else {
        logActivity('SmilePay Verify Code Check Skipped');
    }
    
    // 檢查是否已經處理過這筆交易
    $transactionId = $paymentNo ? $paymentNo : $smseid;
    if (isTransactionAlreadyProcessed($invoiceId, $transactionId)) {
        logActivity('SmilePay Transaction Already Processed - Invoice: ' . $invoiceId . ', Transaction: ' . $transactionId);
        echo '<Roturlstatus>SmilePay_OK</Roturlstatus>';
        exit;
    }
    
    // 處理付款結果
    if ($responseId == '1' && $callbackAmount > 0) {
        // 付款成功
        handleSuccessfulPayment($invoiceId, $callbackData, $gatewayParams);
        echo '<Roturlstatus>SmilePay_OK</Roturlstatus>';
    } else {
        // 付款失敗或金額為0
        $failReason = $responseId == '0' ? '授權失敗' : '付款金額為0';
        if (!empty($errDesc)) {
            $failReason .= ' - ' . $errDesc;
        }
        handleFailedPayment($invoiceId, $callbackData, $failReason);
        echo '<Roturlstatus>SmilePay_OK</Roturlstatus>';
    }
    
} catch (Exception $e) {
    logActivity('SmilePay Callback Error: ' . $e->getMessage());
    echo '<Roturlstatus>ERROR</Roturlstatus>';
}

/**
 * 計算 SmilePay 驗證碼
 */
function calculateSmilePayVerifyCode($verifyParams, $amount, $smseid)
{
    // A = 商家驗證參數 (四碼，不足補零)
    $A = str_pad($verifyParams, 4, '0', STR_PAD_LEFT);
    
    // B = 收款金額 (八碼，不足補零)
    $amountInt = (int)floatval($amount);
    $B = str_pad($amountInt, 8, '0', STR_PAD_LEFT);
    
    // C = Smseid 參數的後四碼，如不為數字則以 9 替代
    $smseIdLast4 = substr($smseid, -4);
    $C = '';
    for ($i = 0; $i < 4; $i++) {
        $char = isset($smseIdLast4[$i]) ? $smseIdLast4[$i] : '0';
        if (is_numeric($char)) {
            $C .= $char;
        } else {
            $C .= '9';
        }
    }
    
    // D = A + B + C  
    $D = $A . $B . $C;
    
    // E = 取 D 的偶數位字數相加後乘以 3 (從0開始計算，1,3,5...)
    $evenSum = 0;
    for ($i = 1; $i < strlen($D); $i += 2) {
        $evenSum += (int)$D[$i];
    }
    $E = $evenSum * 3;
    
    // F = 取 D 的奇數位字數相加後乘以 9 (從0開始計算，0,2,4...)
    $oddSum = 0;
    for ($i = 0; $i < strlen($D); $i += 2) {
        $oddSum += (int)$D[$i];
    }
    $F = $oddSum * 9;
    
    // SmilePay 驗證碼 = E + F
    $verifyCode = $E + $F;
    
    logActivity('SmilePay Verify Code Calculation - A:' . $A . ', B:' . $B . ', C:' . $C . ', D:' . $D . ', E:' . $E . ', F:' . $F . ', Result:' . $verifyCode);
    
    return $verifyCode;
}

/**
 * 檢查交易是否已處理過
 */
function isTransactionAlreadyProcessed($invoiceId, $transactionId)
{
    try {
        // 檢查 WHMCS 付款記錄
        $result = full_query("SELECT id FROM tblaccounts WHERE invoiceid = '" . db_escape_string($invoiceId) . "' AND transid = '" . db_escape_string($transactionId) . "'");
        if (mysql_num_rows($result) > 0) {
            return true;
        }
        
        // 檢查發票狀態
        $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
        if ($invoice['result'] === 'success' && $invoice['status'] === 'Paid') {
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        logActivity('SmilePay Transaction Check Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 處理付款成功
 */
function handleSuccessfulPayment($invoiceId, $callbackData, $gatewayParams)
{
    $amount = $callbackData['Amount'];
    $paymentNo = $callbackData['Payment_no'] ?? $callbackData['Smseid'];
    $classif = $callbackData['Classif'] ?? '';
    $processDate = $callbackData['Process_date'] ?? '';
    $processTime = $callbackData['Process_time'] ?? '';
    $authCode = $callbackData['Auth_code'] ?? '';
    $smseid = $callbackData['Smseid'];
    $fee = $callbackData['Fee'] ?? 0;
    
    try {
        // 記錄付款到 WHMCS
        $transactionId = $paymentNo ? $paymentNo : $smseid;
        
        addInvoicePayment(
            $invoiceId,           // 發票ID
            $transactionId,       // 交易ID
            $amount,             // 付款金額
            floatval($fee),      // 手續費
            'smilepay'           // 付款閘道
        );
        
        // 更新發票備註，添加付款詳情
        $paymentDetails = [
            'SmilePay交易號' => $paymentNo,
            // 'SmilePay追蹤碼' => $smseid, 
            '付款方式' => getPaymentMethodName($classif),
            '付款日期' => $processDate,
            '付款時間' => $processTime,
            '授權碼' => $authCode,
            '付款金額' => 'NT$ ' . number_format($amount),
            '手續費' => $fee > 0 ? 'NT$ ' . number_format($fee) : '無'
        ];
        
        $paymentInfo = "\n\n=== SmilePay 付款完成 ===\n";
        foreach ($paymentDetails as $key => $value) {
            if (!empty($value)) {
                $paymentInfo .= $key . ': ' . $value . "\n";
            }
        }
        $paymentInfo .= "處理時間: " . date('Y-m-d H:i:s') . "\n";
        
        // 取得現有備註並添加付款資訊
        $result = full_query("SELECT notes FROM tblinvoices WHERE id = '" . db_escape_string($invoiceId) . "'");
        if ($result && mysql_num_rows($result) > 0) {
            $invoice = mysql_fetch_array($result);
            $existingNotes = $invoice['notes'] ?? '';
            
            // 清除舊的 SmilePay 付款處理中資訊
            $existingNotes = preg_replace('/SmilePay_Data:({.*?})/s', '', $existingNotes);
            
            $newNotes = trim($existingNotes . $paymentInfo);
            full_query("UPDATE tblinvoices SET notes = '" . db_escape_string($newNotes) . "' WHERE id = '" . db_escape_string($invoiceId) . "'");
        }
        
        // 記錄成功日誌
        logActivity('SmilePay Payment Success - Invoice: ' . $invoiceId . ', Amount: NT$ ' . number_format($amount) . ', Fee: NT$ ' . number_format($fee) . ', Transaction: ' . $transactionId . ', Method: ' . getPaymentMethodName($classif));
        
        // 發送付款確認郵件（如果有配置）
        try {
            sendMessage('Payment Confirmation', $invoiceId);
        } catch (Exception $e) {
            logActivity('SmilePay Email Send Error: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        logActivity('SmilePay Payment Processing Error - Invoice: ' . $invoiceId . ', Error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * 處理付款失敗
 */
function handleFailedPayment($invoiceId, $callbackData, $failReason = '')
{
    $smseid = $callbackData['Smseid'];
    $errDesc = $callbackData['Errdesc'] ?? '';
    $classif = $callbackData['Classif'] ?? '';
    $processDate = $callbackData['Process_date'] ?? '';
    $processTime = $callbackData['Process_time'] ?? '';
    $amount = $callbackData['Amount'] ?? 0;
    $purchAmount = $callbackData['Purchamt'] ?? 0;
    
    // 組合失敗原因
    $fullFailReason = $failReason;
    if (!empty($errDesc)) {
        $fullFailReason .= ' - ' . $errDesc;
    }
    
    // // 記錄失敗資訊到發票備註
    // $failureInfo = "\n\n=== SmilePay 付款失敗 ===\n";
    // $failureInfo .= "SmilePay追蹤碼: " . $smseid . "\n";
    // $failureInfo .= "付款方式: " . getPaymentMethodName($classif) . "\n";
    // $failureInfo .= "嘗試金額: NT$ " . number_format($purchAmount) . "\n";
    // $failureInfo .= "實際金額: NT$ " . number_format($amount) . "\n";
    // $failureInfo .= "失敗日期: " . $processDate . "\n";
    // $failureInfo .= "失敗時間: " . $processTime . "\n";
    // $failureInfo .= "失敗原因: " . $fullFailReason . "\n";
    // $failureInfo .= "記錄時間: " . date('Y-m-d H:i:s') . "\n";
    
    try {
        // 取得現有備註並添加失敗資訊
        $result = full_query("SELECT notes FROM tblinvoices WHERE id = '" . db_escape_string($invoiceId) . "'");
        if ($result && mysql_num_rows($result) > 0) {
            $invoice = mysql_fetch_array($result);
            $existingNotes = $invoice['notes'] ?? '';
            
            $newNotes = $existingNotes . $failureInfo;
            full_query("UPDATE tblinvoices SET notes = '" . db_escape_string($newNotes) . "' WHERE id = '" . db_escape_string($invoiceId) . "'");
        }
        
        // 記錄失敗日誌
        logActivity('SmilePay Payment Failed - Invoice: ' . $invoiceId . ', Smseid: ' . $smseid . ', Reason: ' . $fullFailReason . ', Method: ' . getPaymentMethodName($classif));
        
    } catch (Exception $e) {
        logActivity('SmilePay Failure Processing Error - Invoice: ' . $invoiceId . ', Error: ' . $e->getMessage());
    }
}

/**
 * 取得付款方式中文名稱
 */
function getPaymentMethodName($classif)
{
    $methods = [
        'A' => '刷卡',
        'B' => 'ATM虛擬帳號',
        'C' => '超商代收', 
        'E' => '7-11 ibon',
        'F' => '全家 FamiPort',
        'I' => 'i-Money',
        'L' => 'LifeET',
        'O' => '黑貓貨到收現',
        'P' => '黑貓宅配',
        'Q' => '黑貓逆物流',
        'T' => 'C2C取貨付款',
        'U' => 'C2C純取貨',
        'V' => 'B2C取貨付款', 
        'W' => 'B2C純取貨',
        'R' => 'C2B客付',
        'S' => 'C2B場附'
    ];
    
    return $methods[$classif] ?? $classif;
}
?>
