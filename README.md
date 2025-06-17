# SmilePay WHMCS Payment Gateway 模組

本模組為 [WHMCS](https://www.whmcs.com/) 客製化開發的台灣金流整合插件，支援 [SmilePay 訊航科技](https://www.smse.com.tw/) 提供的 ATM 虛擬帳號、7-Eleven ibon、全家 FamiPort 等付款方式。

> 🎉 無須第三方擴充，即可在 WHMCS 中整合 SmilePay 金流服務！

---

## 🔧 功能特色

- ✅ 支援三種付款方式：
  - ATM 虛擬帳號
  - 7-Eleven ibon
  - 全家 FamiPort
- ✅ 自動向 SmilePay 取號，顯示付款資訊給客戶
- ✅ 完成付款後自動接收 SmilePay 回傳並標記帳單為已付款
- ✅ 支援 WHMCS 後台金流設定介面（無需編碼）

---

## 🧭 安裝步驟

### 1. 將模組放入以下 WHMCS 目錄中：

/modules/gateways/smilepay.php
/modules/gateways/callback/smilepay.php


### 2. 登入 WHMCS 後台 → 設定 → 支付閘道（Payment Gateways）

- 點選「**所有閘道（All Payment Gateways）**」
- 啟用 **SmilePay 金流整合**
- 填寫以下欄位：

| 欄位名稱   | 說明                          |
|------------|-------------------------------|
| Dcvc       | 商店代號（由 SmilePay 提供）  |
| Verify_key | 驗證碼（由 SmilePay 提供）    |
| Roturl     | 回傳網址                      |

Roturl 格式範例：
https://你的網域/modules/gateways/callback/smilepay.php

---

## 💳 使用流程（客戶端）

1. 客戶於結帳頁選擇「SmilePay 金流整合」
2. 選擇付款方式：ATM、7-11 ibon、全家 FamiPort
3. 系統自動向 SmilePay 取號並顯示付款代碼或帳號
4. 客戶完成付款後，SmilePay 自動通知 WHMCS 並完成入帳

---

## 📦 SmilePay 付款方式說明

| Pay_zg 值 | 說明            | 回傳欄位            |
|-----------|-----------------|---------------------|
| 2         | ATM 虛擬帳號    | AtmBankNo + AtmNo   |
| 4         | 7-Eleven ibon   | IbonNo              |
| 6         | 全家 FamiPort   | FamiNO              |

---

## 🛠 未來可擴充功能

- 電子發票串接（EzPay、綠界）
- 超商列印繳費單條碼
- 支援更多 SmilePay 模式（Line Pay、信用卡）

---

## 📜 授權條款

本模組開源於 [MIT License](https://opensource.org/licenses/MIT)，歡迎自由使用與修改。

---

## 🙋‍♂️ 作者聯絡

作者：[@appleson1993](https://github.com/appleson1993)  
有任何錯誤或建議歡迎開 issue 或 PR。

