<?php
/**
 * WHMCS SmilePay Payment Gateway Module
 * 支援 ATM虛擬帳號、7-11 ibon、全家 FamiPort
 * 防止重複取號機制
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Gateway configuration array
 */
function smilepay_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SmilePay 付款 (超商 / ATM)'
        ],
        'dcvc' => [
            'FriendlyName' => '商店代號 (Dcvc)',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => '請輸入 SmilePay 提供的商店代號',
        ],
        'verify_key' => [
            'FriendlyName' => '驗證碼 (Verify_key)',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => '請輸入 SmilePay 提供的驗證碼',
        ],
        'roturl' => [
            'FriendlyName' => '回傳網址',
            'Type' => 'text',
            'Size' => '100',
            'Default' => '',
            'Description' => '付款完成後的通知網址 (選填)',
        ],
        'payment_methods' => [
            'FriendlyName' => '付款方式',
            'Type' => 'dropdown',
            'Options' => [
                'all' => '全部 (ATM + 超商)',
                'atm' => '僅 ATM 虛擬帳號',
                'cvs' => '僅超商 (7-11 + 全家)',
            ],
            'Default' => 'all',
        ],
    ];
}

/**
 * Payment link generation
 */
function smilepay_link($params)
{
    $invoiceId = $params['invoiceid'];
    
    // 檢查是否已有付款編號
    $existingPayment = getExistingSmilePayData($invoiceId);
    
    if ($existingPayment) {
        // 已有付款編號，直接顯示付款資訊
        return generateExistingPaymentInfo($existingPayment, $invoiceId, $params['amount']);
    }
    
    // 處理新的付款請求
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smilepay_action'])) {
        return handleNewSmilePayPayment($params);
    }
    
    // 顯示付款方式選擇表單
    return generatePaymentSelectionForm($params);
}

/**
 * 檢查是否已有 SmilePay 付款記錄
 */
function getExistingSmilePayData($invoiceId)
{
    try {
        // 從發票備註中查找 SmilePay 資訊
        $result = full_query("SELECT notes FROM tblinvoices WHERE id = '" . db_escape_string($invoiceId) . "'");
        if (!$result) return false;
        
        $invoice = mysql_fetch_array($result);
        if (!$invoice || empty($invoice['notes'])) return false;
        
        $notes = $invoice['notes'];
        
        // 解析備註中的 SmilePay 資料 (JSON格式)
        if (preg_match('/SmilePay_Data:({.*?})/s', $notes, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['SmilePayNO'])) {
                // 檢查付款是否已過期
                if (isset($data['PayEndDate'])) {
                    $endDate = strtotime($data['PayEndDate']);
                    if ($endDate && $endDate < time()) {
                        // 已過期，返回 false 允許重新取號
                        return false;
                    }
                }
                return $data;
            }
        }
        
        return false;
    } catch (Exception $e) {
        logActivity('SmilePay Check Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 儲存 SmilePay 付款資料到發票備註
 */
function saveSmilePayDataToInvoice($invoiceId, $data)
{
    try {
        // 取得現有備註
        $result = full_query("SELECT notes FROM tblinvoices WHERE id = '" . db_escape_string($invoiceId) . "'");
        $invoice = mysql_fetch_array($result);
        $existingNotes = $invoice['notes'] ?? '';
        
        // 清除舊的 SmilePay 資料
        $existingNotes = preg_replace('/SmilePay_Data:({.*?})/s', '', $existingNotes);
        
        // 添加新的 SmilePay 資料
        $smilePayJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        $newNotes = trim($existingNotes . "\n\nSmilePay_Data:" . $smilePayJson);
        
        // 更新發票備註
        full_query("UPDATE tblinvoices SET notes = '" . db_escape_string($newNotes) . "' WHERE id = '" . db_escape_string($invoiceId) . "'");
        
        return true;
    } catch (Exception $e) {
        logActivity('SmilePay Save Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 生成付款方式選擇表單
 */
function generatePaymentSelectionForm($params)
{
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $paymentMethods = $params['payment_methods'];
    
    // 檢查必要參數
    if (empty($params['dcvc']) || empty($params['verify_key'])) {
        return '<div class="alert alert-danger">SmilePay 設定不完整，請聯絡管理員</div>';
    }

    if ($params['currency'] != 'TWD') {
        return '<div class="alert alert-danger">SmilePay 僅支援台幣(TWD)付款</div>';
    }

    $html = '<div class="smilepay-payment-form">';
    $html .= '<style>
        .smilepay-payment-form { max-width: 500px; margin: 20px 0; }
        .payment-method-option { 
            margin: 10px 0; 
            padding: 15px; 
            border: 2px solid #ddd; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: all 0.3s;
        }
        .payment-method-option:hover { 
            border-color: #007cba; 
            background-color: #f8f9fa; 
        }
        .payment-method-option.selected {
            border-color: #007cba;
            background-color: #f0f8ff;
        }
        .submit-button { 
            background: #007cba; 
            color: white; 
            padding: 12px 30px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 16px; 
            margin-top: 20px;
            width: 100%;
        }
        .submit-button:hover { background: #005a87; }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            color: #856404;
        }
    </style>';
    
    $html .= '<form method="post" id="smilepay-form">';
    $html .= '<input type="hidden" name="smilepay_action" value="create_payment">';
    
    $html .= '<h4>選擇付款方式 (金額: NT$ ' . number_format($amount) . ')</h4>';
    
    // 重要提醒
    $html .= '<div class="warning-box">';
    $html .= '<strong>⚠️ 重要提醒：</strong><br>';
    $html .= '• 選定付款方式後將產生專屬付款編號<br>';
    $html .= '• 每張發票僅能產生一次付款編號<br>';
    $html .= '• 如需更換付款方式，請重新下單';
    $html .= '</div>';
    
    // 付款方式選項
    if ($paymentMethods == 'all' || $paymentMethods == 'atm') {
        $html .= '<div class="payment-method-option" onclick="selectMethod(this, 2)">';
        $html .= '<label style="cursor: pointer; display: block;">';
        $html .= '<input type="radio" name="pay_method" value="2" style="margin-right: 10px;">';
        $html .= '🏦 <strong>ATM 虛擬帳號轉帳</strong><br>';
        $html .= '<small style="color: #666;">適用各家銀行 ATM 及網路銀行</small>';
        $html .= '</label>';
        $html .= '</div>';
    }
    
    if ($paymentMethods == 'all' || $paymentMethods == 'cvs') {
        $html .= '<div class="payment-method-option" onclick="selectMethod(this, 4)">';
        $html .= '<label style="cursor: pointer; display: block;">';
        $html .= '<input type="radio" name="pay_method" value="4" style="margin-right: 10px;">';
        $html .= '🏪 <strong>7-11 ibon 繳費</strong><br>';
        $html .= '<small style="color: #666;">到 7-11 使用 ibon 機器繳費</small>';
        $html .= '</label>';
        $html .= '</div>';
        
        $html .= '<div class="payment-method-option" onclick="selectMethod(this, 6)">';
        $html .= '<label style="cursor: pointer; display: block;">';
        $html .= '<input type="radio" name="pay_method" value="6" style="margin-right: 10px;">';
        $html .= '🏪 <strong>全家 FamiPort 繳費</strong><br>';
        $html .= '<small style="color: #666;">到全家使用 FamiPort 機器繳費</small>';
        $html .= '</label>';
        $html .= '</div>';
    }
    
    $html .= '<button type="submit" class="submit-button" id="confirm-btn">確認付款方式並產生付款編號</button>';
    $html .= '</form>';
    $html .= '</div>';

    $html .= '<script>
    function selectMethod(element, value) {
        document.querySelectorAll(".payment-method-option").forEach(function(opt) {
            opt.classList.remove("selected");
        });
        element.classList.add("selected");
        element.querySelector("input[type=radio]").checked = true;
        
        // 更新確認按鈕文字
        var methodNames = {
            2: "ATM轉帳",
            4: "7-11繳費", 
            6: "全家繳費"
        };
        document.getElementById("confirm-btn").innerHTML = "確認使用 " + methodNames[value] + " 並產生付款編號";
    }
    
    document.getElementById("smilepay-form").addEventListener("submit", function(e) {
        var selected = document.querySelector("input[name=pay_method]:checked");
        if (!selected) {
            e.preventDefault();
            alert("請選擇付款方式");
            return;
        }
        
        // 二次確認
        var methodNames = {
            "2": "ATM虛擬帳號轉帳",
            "4": "7-11 ibon繳費", 
            "6": "全家 FamiPort繳費"
        };
        
        if (!confirm("確定要使用「" + methodNames[selected.value] + "」嗎？\\n\\n選定後將無法更改付款方式，如需更換請重新下單。")) {
            e.preventDefault();
            return;
        }
        
        // 顯示處理中
        document.getElementById("confirm-btn").innerHTML = "正在產生付款編號...";
        document.getElementById("confirm-btn").disabled = true;
    });
    </script>';

    return $html;
}

/**
 * 處理新的 SmilePay 付款請求
 */
function handleNewSmilePayPayment($params)
{
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount'];
    $payMethod = $_POST['pay_method'] ?? '';
    
    // 再次檢查是否已有付款記錄（防止重複提交）
    $existingPayment = getExistingSmilePayData($invoiceId);
    if ($existingPayment) {
        return generateExistingPaymentInfo($existingPayment, $invoiceId, $amount);
    }
    
    if (empty($payMethod)) {
        return '<div class="alert alert-danger">付款方式錯誤，請重新選擇</div>';
    }
    
    $dcvc = $params['dcvc'];
    $verifyKey = $params['verify_key'];
    $roturl = $params['roturl'];
    
    // 客戶資訊
    $customerName = trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']);
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];
    $address = trim($params['clientdetails']['address1'] . ' ' . $params['clientdetails']['city']);

    // 準備 API 參數
    $apiParams = [
        'Rvg2c' => '1',
        'Dcvc' => $dcvc,
        'Od_sob' => $invoiceId,
        'Amount' => $amount,
        'Pur_name' => $customerName,
        'Tel_number' => $phone,
        'Mobile_number' => $phone,
        'Address' => $address,
        'Email' => $email,
        'Invoice_name' => '',
        'Invoice_num' => '',
        'Remark' => 'Invoice: ' . $invoiceId,
        'Roturl' => $roturl,
        'Pay_zg' => $payMethod,
        'Verify_key' => $verifyKey
    ];

    // 呼叫 SmilePay API
    $apiResult = callSmilePayAPI($apiParams);
    
    if (!$apiResult['success']) {
        return '<div class="alert alert-danger">付款處理失敗：' . htmlspecialchars($apiResult['error']) . '<br><br><a href="javascript:history.back()">返回重試</a></div>';
    }

    // 添加付款方式資訊
    $paymentData = $apiResult['data'];
    $paymentData['PayMethod'] = $payMethod;
    $paymentData['CreatedTime'] = date('Y-m-d H:i:s');

    // 儲存到發票備註
    if (!saveSmilePayDataToInvoice($invoiceId, $paymentData)) {
        logActivity('SmilePay Save Failed - Invoice: ' . $invoiceId);
    }

    // 記錄活動日誌
    logActivity('SmilePay Payment Created - Invoice: ' . $invoiceId . ', SmilePayNO: ' . $paymentData['SmilePayNO'] . ', Method: ' . getPayMethodName($payMethod));

    // 顯示付款資訊
    return generatePaymentInfo($paymentData, $payMethod, $invoiceId, $amount, true);
}

/**
 * 顯示已存在的付款資訊
 */
function generateExistingPaymentInfo($paymentData, $invoiceId, $amount)
{
    $payMethod = $paymentData['PayMethod'];
    return generatePaymentInfo($paymentData, $payMethod, $invoiceId, $amount, false);
}

/**
 * 呼叫 SmilePay API
 */
function callSmilePayAPI($params)
{
    $apiUrl = 'https://ssl.smse.com.tw/api/SPPayment.asp';
    $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $fullUrl = $apiUrl . '?' . $queryString;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WHMCS-SmilePay/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return [
            'success' => false,
            'error' => 'API 連線失敗 (HTTP: ' . $httpCode . ')' . ($error ? ' - ' . $error : '')
        ];
    }

    $xml = simplexml_load_string($response);
    if (!$xml) {
        return [
            'success' => false,
            'error' => '回應格式錯誤'
        ];
    }

    if ((string)$xml->Status !== '1') {
        return [
            'success' => false,
            'error' => (string)$xml->Desc
        ];
    }

    return [
        'success' => true,
        'data' => [
            'SmilePayNO' => (string)$xml->SmilePayNO,
            'Amount' => (string)$xml->Amount,
            'PayEndDate' => (string)$xml->PayEndDate,
            'AtmBankNo' => (string)$xml->AtmBankNo,
            'AtmNo' => (string)$xml->AtmNo,
            'IbonNo' => (string)$xml->IbonNo,
            'FamiNO' => (string)$xml->FamiNO,
        ]
    ];
}

/**
 * 生成付款資訊頁面
 */
function generatePaymentInfo($data, $payMethod, $invoiceId, $amount, $isNew = false)
{
    $html = '<div style="max-width: 600px; margin: 20px auto; padding: 20px; background: white; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">';
    $html .= '<style>
        .highlight { color: #d9534f; font-weight: bold; font-size: 18px; font-family: monospace; }
        .copy-btn { background: #5cb85c; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer; margin-left: 10px; }
        .info-section { background: #f8f9fa; padding: 20px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #007cba; }
        .deadline { color: #d9534f; font-weight: bold; text-align: center; background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .success-msg { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 15px 0; color: #155724; }
        .fixed-payment { background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; border-radius: 5px; margin: 15px 0; color: #0c5460; }
    </style>';
    
    if ($isNew) {
        $html .= '<div class="success-msg">';
        $html .= '<strong>✅ 付款編號已成功產生</strong><br>';
        $html .= '請使用以下資訊完成付款，此編號已固定無法更改';
        $html .= '</div>';
    } else {
        $html .= '<div class="fixed-payment">';
        $html .= '<strong>📌 付款編號已產生</strong><br>';
        $html .= '此發票已有專屬付款編號，請使用以下資訊完成付款';
        $html .= '</div>';
    }
    
    $html .= '<h2>💳 付款資訊</h2>';
    $html .= '<p><strong>訂單號碼：</strong>' . htmlspecialchars($invoiceId) . '</p>';
    $html .= '<p><strong>付款金額：</strong>NT$ ' . number_format($amount) . '</p>';
    $html .= '<p><strong>付款方式：</strong>' . getPayMethodName($payMethod) . '</p>';
    $html .= '<p><strong>SmilePay 交易號：</strong>' . htmlspecialchars($data['SmilePayNO']) . '</p>';
    
    if (isset($data['CreatedTime'])) {
        $html .= '<p><strong>產生時間：</strong>' . $data['CreatedTime'] . '</p>';
    }
    
    $html .= '<div class="deadline">⏰ 付款期限：' . htmlspecialchars($data['PayEndDate']) . '</div>';

    switch ($payMethod) {
        case '2': // ATM
            $html .= '<div class="info-section">';
            $html .= '<h3>🏦 ATM 虛擬帳號轉帳</h3>';
            $html .= '<p><strong>銀行代碼：</strong><span class="highlight">' . htmlspecialchars($data['AtmBankNo']) . '</span>';
            $html .= '<button class="copy-btn" onclick="copyText(\'' . $data['AtmBankNo'] . '\')">複製</button></p>';
            $html .= '<p><strong>虛擬帳號：</strong><span class="highlight">' . htmlspecialchars($data['AtmNo']) . '</span>';
            $html .= '<button class="copy-btn" onclick="copyText(\'' . $data['AtmNo'] . '\')">複製</button></p>';
            $html .= '<hr style="margin: 15px 0;">';
            $html .= '<h4>💡 轉帳步驟：</h4>';
            $html .= '<ol>';
            $html .= '<li>前往任何銀行 ATM 或使用網路銀行</li>';
            $html .= '<li>選擇「轉帳」功能</li>';
            $html .= '<li>輸入銀行代碼：<strong>' . htmlspecialchars($data['AtmBankNo']) . '</strong></li>';
            $html .= '<li>輸入虛擬帳號：<strong>' . htmlspecialchars($data['AtmNo']) . '</strong></li>';
            $html .= '<li>輸入轉帳金額：<strong>NT$ ' . number_format($amount) . '</strong></li>';
            $html .= '<li>完成轉帳並保留收據</li>';
            $html .= '</ol>';
            $html .= '</div>';
            break;
            
        case '4': // 7-11
            $html .= '<div class="info-section">';
            $html .= '<h3>🏪 7-11 ibon 繳費</h3>';
            $html .= '<p><strong>ibon 代碼：</strong><span class="highlight">' . htmlspecialchars($data['IbonNo']) . '</span>';
            $html .= '<button class="copy-btn" onclick="copyText(\'' . $data['IbonNo'] . '\')">複製</button></p>';
            $html .= '<hr style="margin: 15px 0;">';
            $html .= '<h4>💡 繳費步驟：</h4>';
            $html .= '<ol>';
            $html .= '<li>前往任一 7-11 門市</li>';
            $html .= '<li>使用 ibon 多媒體機台</li>';
            $html .= '<li>點選「代碼輸入」</li>';
            $html .= '<li>輸入代碼：<strong>' . htmlspecialchars($data['IbonNo']) . '</strong></li>';
            $html .= '<li>確認金額：<strong>NT$ ' . number_format($amount) . '</strong></li>';
            $html .= '<li>列印繳費單後至櫃台付款</li>';
            $html .= '</ol>';
            $html .= '</div>';
            break;
            
        case '6': // 全家
            $html .= '<div class="info-section">';
            $html .= '<h3>🏪 全家 FamiPort 繳費</h3>';
            $html .= '<p><strong>FamiPort 代碼：</strong><span class="highlight">' . htmlspecialchars($data['FamiNO']) . '</span>';
            $html .= '<button class="copy-btn" onclick="copyText(\'' . $data['FamiNO'] . '\')">複製</button></p>';
            $html .= '<hr style="margin: 15px 0;">';
            $html .= '<h4>💡 繳費步驟：</h4>';
            $html .= '<ol>';
            $html .= '<li>前往任一全家門市</li>';
            $html .= '<li>使用 FamiPort 多媒體機台</li>';
            $html .= '<li>點選「代碼輸入」</li>';
            $html .= '<li>輸入代碼：<strong>' . htmlspecialchars($data['FamiNO']) . '</strong></li>';
            $html .= '<li>確認金額：<strong>NT$ ' . number_format($amount) . '</strong></li>';
            $html .= '<li>列印繳費單後至櫃台付款</li>';
            $html .= '</ol>';
            $html .= '</div>';
            break;
    }

    $html .= '<h4>📋 注意事項：</h4>';
    $html .= '<ul>';
    $html .= '<li><strong>此付款編號已固定，請勿重複產生</strong></li>';
    $html .= '<li>請在付款期限前完成繳費，逾期將無法付款</li>';
    $html .= '<li>付款完成後系統將自動處理，通常 5-10 分鐘內生效</li>';
    $html .= '<li>請保留付款收據以備查詢</li>';
    $html .= '<li>如需更換付款方式，請重新下單</li>';
    $html .= '</ul>';
    
    $html .= '<p style="text-align: center; margin-top: 30px;">';
    $html .= '<a href="viewinvoice.php?id=' . $invoiceId . '" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;">返回發票頁面</a>';
    $html .= '<a href="clientarea.php" style="background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px;">客戶中心</a>';
    $html .= '</p>';

    $html .= '<script>
    function copyText(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                alert("已複製: " + text);
            }).catch(function() {
                prompt("請手動複製以下內容:", text);
            });
        } else {
            prompt("請手動複製以下內容:", text);
        }
    }
    </script>';

    $html .= '</div>';
    return $html;
}

/**
 * 取得付款方式中文名稱
 */
function getPayMethodName($method)
{
    $methods = [
        '2' => 'ATM 虛擬帳號',
        '4' => '7-11 ibon',
        '6' => '全家 FamiPort'
    ];
    return $methods[$method] ?? '未知付款方式';
}
?>
