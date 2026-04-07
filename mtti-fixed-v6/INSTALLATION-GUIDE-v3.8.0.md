# MTTI MIS v3.8.0 Installation & Upgrade Guide

## 📦 What's Included

This package contains:
1. **Updated Plugin Files** - Core MIS system with certificate verification
2. **Verification Page** - Standalone certificate verification interface
3. **Documentation** - Changelog, installation guide, troubleshooting
4. **SQL Scripts** - For manual database setup if needed

---

## 🎯 Quick Start (5 Minutes)

### Step 1: Backup Everything
```bash
# Backup files
tar -czf mtti-mis-backup-$(date +%Y%m%d).tar.gz /path/to/wp-content/plugins/mtti-mis/

# Backup database (run in phpMyAdmin or command line)
mysqldump -u username -p database_name > mtti-backup-$(date +%Y%m%d).sql
```

### Step 2: Upload Files
1. **Plugin Files**: Upload entire `mtti-mis` folder to `/wp-content/plugins/`
2. **Verification Page**: Upload `verify-certificate-custom.php` to website root

### Step 3: Activate
1. Go to WordPress Admin > Plugins
2. Deactivate MTTI MIS (if already active)
3. Activate MTTI MIS
4. Database table will be created automatically

### Step 4: Verify Installation
1. Go to MTTI MIS > Certificates
2. Generate a test certificate
3. Visit: `https://yourdomain.com/verify-certificate-custom.php`
4. Verify the test certificate

✅ Done! Your system is ready.

---

## 📋 Detailed Installation Guide

### Prerequisites
- WordPress 5.0 or higher
- PHP 7.2 or higher
- MySQL 5.6 or higher
- FTP/SFTP access or File Manager
- phpMyAdmin access (recommended)
- Admin access to WordPress

---

## 🔄 Upgrade from v3.7.x

### Method 1: Automatic Upgrade (Recommended)

1. **Download Package**
   - Extract `mtti-mis-v3.8.0.zip`

2. **Backup Current Installation**
   ```bash
   # Via FTP: Download entire /wp-content/plugins/mtti-mis/ folder
   # Via phpMyAdmin: Export database (select all wp_mtti_* tables)
   ```

3. **Deactivate Plugin**
   - WordPress Admin > Plugins > MTTI MIS > Deactivate

4. **Upload Files**
   - Delete old `/wp-content/plugins/mtti-mis/` folder
   - Upload new `mtti-mis` folder
   - **OR** overwrite individual files:
     - `mtti-mis.php`
     - `includes/class-mtti-mis-activator.php`
     - `admin/class-mtti-mis-admin-certificates.php`

5. **Upload Verification Page**
   - Upload `verify-certificate-custom.php` to root directory
   - Same level as `wp-config.php`

6. **Activate Plugin**
   - WordPress Admin > Plugins > MTTI MIS > Activate
   - Plugin will automatically detect missing table and create it

7. **Verify Installation**
   ```sql
   -- Run in phpMyAdmin
   SHOW TABLES LIKE 'wp_mtti_certificates';
   ```
   Should return: `wp_mtti_certificates`

### Method 2: Manual Database Setup

If automatic creation fails:

1. **Create Table Manually**
   - Open phpMyAdmin
   - Select your WordPress database
   - Click "SQL" tab
   - Paste and run:

```sql
CREATE TABLE IF NOT EXISTS `wp_mtti_certificates` (
  `certificate_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `certificate_number` varchar(100) NOT NULL,
  `verification_code` varchar(50) NOT NULL,
  `student_id` bigint(20) NOT NULL,
  `student_name` varchar(255) NOT NULL,
  `admission_number` varchar(50) NOT NULL,
  `course_id` bigint(20) NOT NULL,
  `course_name` varchar(255) NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `grade` varchar(50) NOT NULL,
  `completion_date` date NOT NULL,
  `issue_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Valid',
  `notes` text DEFAULT NULL,
  `qr_code_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`certificate_id`),
  UNIQUE KEY `certificate_number` (`certificate_number`),
  UNIQUE KEY `verification_code` (`verification_code`),
  KEY `student_id` (`student_id`),
  KEY `course_id` (`course_id`),
  KEY `status` (`status`),
  KEY `issue_date` (`issue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

2. **Update Plugin Files**
   - Follow steps 4-5 from Method 1

---

## 📂 File Structure

```
Website Root (/)
├── wp-config.php
├── wp-content/
│   └── plugins/
│       └── mtti-mis/
│           ├── mtti-mis.php ⭐ (UPDATED)
│           ├── includes/
│           │   ├── class-mtti-mis-activator.php ⭐ (UPDATED)
│           │   ├── class-mtti-mis.php
│           │   ├── class-mtti-mis-database.php
│           │   ├── class-mtti-mis-loader.php
│           │   └── class-mtti-mis-deactivator.php
│           ├── admin/
│           │   ├── class-mtti-mis-admin-certificates.php ⭐ (UPDATED)
│           │   ├── class-mtti-mis-admin-students.php
│           │   ├── class-mtti-mis-admin-courses.php
│           │   └── ...
│           ├── assets/
│           │   ├── css/
│           │   ├── js/
│           │   └── images/
│           │       └── logo.jpeg
│           └── public/
│               └── ...
└── verify-certificate-custom.php ⭐ (NEW)

⭐ = Updated or new files in v3.8.0
```

---

## 🧪 Testing Your Installation

### Test 1: Database Table

**phpMyAdmin:**
```sql
SHOW TABLES LIKE 'wp_mtti_certificates';
```
**Expected**: Returns 1 row with table name

**Check Structure:**
```sql
DESCRIBE wp_mtti_certificates;
```
**Expected**: 17 columns including certificate_number, verification_code, status

### Test 2: Certificate Generation

1. **Generate Certificate**
   - Admin > MTTI MIS > Certificates
   - Select a student
   - Click "Generate Certificate"
   - Fill form and submit

2. **Check PDF Display**
   - Should show certificate with:
     - Certificate number (bottom left)
     - Verification code (bottom center)
     - Issue date (bottom right)

3. **Verify Database Entry**
   ```sql
   SELECT * FROM wp_mtti_certificates ORDER BY certificate_id DESC LIMIT 1;
   ```
   **Expected**: See the certificate you just generated

### Test 3: Certificate Verification

1. **Access Verification Page**
   - Go to: `https://yourdomain.com/verify-certificate-custom.php`
   - Should see: Clean verification form

2. **Verify by Certificate Number**
   - Enter: Certificate number from test 2
   - Click: "Verify Certificate"
   - **Expected**: Green success box with all details

3. **Verify by Verification Code**
   - Enter: Verification code from certificate
   - Click: "Verify Certificate"
   - **Expected**: Same success message

4. **Test Invalid Certificate**
   - Enter: `MTTI/CERT/2025/999999`
   - **Expected**: Red error box "Certificate Not Found"

### Test 4: Error Handling

**Enable Debug Mode** (`wp-config.php`):
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

**Generate Another Certificate**
- Check `/wp-content/debug.log` for any errors
- Should be empty or only show informational messages

---

## 🔧 Configuration

### Verification Page Customization

Edit `verify-certificate-custom.php` to customize:

**Line 22-28**: Header colors/gradient
```css
background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e22ce 100%);
```

**Line 458**: WordPress path (if verification page is in subdirectory)
```php
require_once('../../../wp-load.php'); // Adjust if needed
```

**Line 627-631**: Footer information
```php
<strong>Masomotele Technical Training Institute</strong><br>
Sagaas Center, Fourth Floor, Eldoret, Kenya<br>
```

### Certificate Layout Customization

Edit `admin/class-mtti-mis-admin-certificates.php`:

**Lines 207-212**: Border colors
```css
border: 20px solid #2E7D32;  /* Outer border */
border: 3px solid #FF9800;   /* Inner border */
```

**Lines 227-250**: Typography
```css
h1 { font-size: 44px; color: #2E7D32; }
.student-name { font-size: 48px; color: #1976D2; }
```

---

## 🔐 Security Considerations

### File Permissions
```bash
# Recommended permissions
chown www-data:www-data verify-certificate-custom.php
chmod 644 verify-certificate-custom.php

# Plugin directory
chmod 755 wp-content/plugins/mtti-mis/
chmod 644 wp-content/plugins/mtti-mis/*.php
```

### Database Security
1. Use strong database password
2. Limit database user permissions
3. Regular backups
4. Monitor for SQL injection attempts

### WordPress Security
1. Keep WordPress updated
2. Use strong admin passwords
3. Enable two-factor authentication
4. Regular security audits

---

## 🚨 Troubleshooting

### Issue: "Certificates table not found"

**Symptoms:**
- Error in admin when generating certificate
- Verification always returns "not found"

**Solutions:**

1. **Check if table exists:**
   ```sql
   SHOW TABLES LIKE 'wp_mtti_certificates';
   ```

2. **If not exists, create manually:**
   - Run SQL script from "Manual Database Setup" section

3. **Check database prefix:**
   - If using custom prefix, ensure table is `{prefix}_mtti_certificates`
   - Check `wp-config.php` for `$table_prefix`

4. **Verify database permissions:**
   ```sql
   SHOW GRANTS FOR 'your_db_user'@'localhost';
   ```
   Should include: SELECT, INSERT, UPDATE, CREATE

### Issue: "Certificate generates but not in database"

**Symptoms:**
- Certificate PDF displays correctly
- Verification returns "not found"
- No errors shown

**Solutions:**

1. **Enable debugging:**
   ```php
   // wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   ```

2. **Check debug log:**
   ```bash
   tail -f wp-content/debug.log
   ```
   Generate certificate and watch for errors

3. **Test database connection:**
   ```php
   // Add temporarily to class-mtti-mis-admin-certificates.php line 195
   error_log('MTTI: Attempting to save certificate');
   error_log('MTTI: Table name: ' . $table);
   error_log('MTTI: Cert number: ' . $cert_number);
   ```

4. **Verify method execution:**
   - Check that `save_certificate_to_database()` method exists
   - Confirm it's being called in `create_certificate_pdf()`

### Issue: "Verification page shows blank or 404"

**Symptoms:**
- Cannot access verification page
- Shows white screen or 404 error

**Solutions:**

1. **Check file location:**
   ```bash
   ls -la /path/to/website/verify-certificate-custom.php
   ```
   Should be in same directory as `wp-config.php`

2. **Verify permissions:**
   ```bash
   chmod 644 verify-certificate-custom.php
   ```

3. **Test WordPress loading:**
   Add to top of verification page:
   ```php
   <?php
   require_once('wp-load.php');
   echo "WordPress loaded successfully";
   exit;
   ```

4. **Check .htaccess:**
   - Ensure no rules blocking .php files
   - Temporarily rename `.htaccess` to test

### Issue: "Verification code not showing on certificate"

**Symptoms:**
- Certificate displays but verification code area is empty
- Only certificate number shows

**Solutions:**

1. **Verify updated file:**
   ```bash
   grep -n "verification_code" wp-content/plugins/mtti-mis/admin/class-mtti-mis-admin-certificates.php
   ```
   Should find multiple matches

2. **Check variable assignment:**
   - Line ~177 should have: `$verification_code = $this->generate_verification_code();`

3. **Clear PHP cache:**
   ```bash
   # Restart PHP-FPM
   sudo systemctl restart php7.4-fpm
   # Or clear OPcache
   ```

4. **Regenerate certificate:**
   - Sometimes browser caching shows old version
   - Use incognito/private mode

---

## 📊 Maintenance

### Daily Tasks
- ✅ None required (system is automatic)

### Weekly Tasks
- 📈 Review certificate generation logs
- 🔍 Spot-check verification system

### Monthly Tasks
- 💾 Backup certificates database table
- 🔐 Review certificate status (revocations needed?)
- 📊 Generate usage statistics

### Quarterly Tasks
- 🔄 Update WordPress and plugins
- 🧹 Clean old test certificates
- 📋 Review and optimize database

### Backup Script Example
```bash
#!/bin/bash
# backup-certificates.sh

DATE=$(date +%Y%m%d)
mysqldump -u dbuser -p'password' dbname wp_mtti_certificates > \
  certificates-backup-$DATE.sql

# Keep last 30 days
find . -name "certificates-backup-*.sql" -mtime +30 -delete
```

---

## 📈 Monitoring

### Database Growth
```sql
-- Check table size
SELECT 
  table_name AS 'Table',
  ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.TABLES
WHERE table_schema = 'your_database'
  AND table_name = 'wp_mtti_certificates';
```

### Certificate Statistics
```sql
-- Total certificates
SELECT COUNT(*) as total FROM wp_mtti_certificates;

-- By status
SELECT status, COUNT(*) as count 
FROM wp_mtti_certificates 
GROUP BY status;

-- Recent certificates
SELECT certificate_number, student_name, issue_date
FROM wp_mtti_certificates
ORDER BY issue_date DESC
LIMIT 10;

-- Monthly breakdown
SELECT 
  DATE_FORMAT(issue_date, '%Y-%m') as month,
  COUNT(*) as certificates_issued
FROM wp_mtti_certificates
GROUP BY month
ORDER BY month DESC;
```

---

## 🆘 Getting Help

### Before Requesting Support
1. ✅ Check this guide thoroughly
2. ✅ Review CHANGELOG for known issues
3. ✅ Enable debug logging
4. ✅ Test on clean WordPress install
5. ✅ Gather error messages and logs

### Information to Provide
- WordPress version
- PHP version
- Database version
- MTTI MIS version
- Error messages from debug.log
- Steps to reproduce issue
- Screenshots if relevant

---

## ✅ Post-Installation Checklist

- [ ] Files uploaded correctly
- [ ] Plugin activated
- [ ] Database table exists
- [ ] Test certificate generated
- [ ] Certificate saved to database
- [ ] Verification page accessible
- [ ] Verification works by certificate number
- [ ] Verification works by code
- [ ] Invalid certificate shows error
- [ ] Debug log shows no errors
- [ ] Backup created
- [ ] Documentation reviewed

---

## 🎓 Best Practices

1. **Always Backup Before Updates**
   - Files and database
   - Test on staging first

2. **Monitor Debug Logs**
   - First week after installation
   - After any certificate generation

3. **Regular Testing**
   - Weekly verification checks
   - Monthly full system test

4. **Documentation**
   - Keep list of generated certificates
   - Document any customizations
   - Note any special configurations

5. **Security**
   - Keep WordPress updated
   - Regular security scans
   - Monitor for suspicious activity

---

**Installation Guide Version:** 1.0  
**Last Updated:** December 2024  
**Plugin Version:** 3.8.0
