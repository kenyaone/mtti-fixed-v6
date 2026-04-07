# MTTI MIS Plugin — mtti-fixed-v6

WordPress MIS plugin for **Masomotele Technical Training Institute (MTTI)**  
Sagaas Centre, 4th Floor, Eldoret, Kenya | TVETA Accredited

---

## 📁 Structure

```
mtti-fixed-v6/
├── admin/
│   ├── class-mtti-mis-admin.php          # Main admin class — menus, roles
│   ├── class-mtti-mis-admin-students.php
│   ├── class-mtti-mis-admin-payments.php
│   ├── class-mtti-mis-admin-finance.php  # Income, Expenses, P&L (unified)
│   ├── class-mtti-mis-admin-assets.php   # Asset Register
│   ├── class-mtti-mis-admin-enrollments.php
│   ├── class-mtti-mis-admin-certificates.php
│   └── ... (other modules)
├── includes/
│   ├── class-mtti-mis.php
│   ├── class-mtti-mis-database.php
│   └── class-mtti-mis-activator.php
├── public/
│   └── class-mtti-mis-learner-portal.php
├── assets/
│   ├── css/
│   ├── js/
│   └── icons/
├── ncba-webhook.php                      # NCBA Paybill webhook
├── mtti-coach-proxy.php                  # AI Coach proxy
├── verify-certificate-custom.php
└── mtti-mis.php                          # Plugin entry point
```

---

## 🗄️ Database

- **Host:** Truehost / cPanel
- **DB:** `uvyzhdzt_wp265`
- **Prefix:** `wp_`
- **Key tables:** `wp_mtti_students`, `wp_mtti_payments`, `wp_mtti_expenses`, `wp_mtti_income`, `wp_mtti_assets`, `wp_mtti_enrollments`

---

## ✨ Features

| Module | Description |
|--------|-------------|
| Students | Enrollment, admission letters, balances |
| Payments | Fee collection, NCBA Paybill webhook |
| 📊 Finance | Unified Income + Expenses + P&L by month |
| 🏢 Assets | Asset register with condition tracking |
| Certificates | QR-verified certificates |
| AI Quiz | Claude Haiku-powered quiz generator |
| Roles | Per-capability role permissions manager |
| LMS | Linked to offline LMS at 192.168.0.63 |

---

## 🚀 Deployment

1. Zip the `mtti-fixed-v6/` folder
2. Upload to `wp-content/plugins/` via cPanel File Manager
3. Activate in WP Admin → Plugins

Or upload individual changed files directly to save time.

---

## 📝 Changelog

### 2026-04-07
- Added unified **Finance** module (`class-mtti-mis-admin-finance.php`)
  - Income tab (manual + auto student fees from Payments)
  - Expenses tab
  - P&L Breakdown with category breakdown and net profit/loss
- Added **Asset Register** module (`class-mtti-mis-admin-assets.php`)
- Added `manage_finance` and `manage_assets` capabilities to Roles Manager
- Fixed: expenses/income mismatch (now single source of truth)
- Migrated 10 expenses + 121 assets from `wpcu_` tables to `wp_` tables

### 2026-03-xx
- NCBA Paybill webhook debugged (ResultCode format, column mapping)
- Student balances auto-update on webhook payment
