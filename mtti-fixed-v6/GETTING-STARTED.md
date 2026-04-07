# 🚀 GETTING STARTED - MTTI MIS v3.8.0

## Welcome!

You've received the complete MTTI Management Information System v3.8.0 package with **Certificate Verification** functionality. This guide will get you up and running in under 10 minutes.

---

## 📦 What You Have

You have received a complete WordPress plugin package with all files needed for:
1. ✅ Student management system
2. ✅ Course management
3. ✅ Certificate generation
4. ✅ **NEW: Certificate verification system**
5. ✅ Payment tracking
6. ✅ Enrollment management
7. ✅ And much more...

---

## 🎯 Choose Your Installation Path

### Path A: I'm Installing for the First Time (20 minutes)
**Start here:** [New Installation Guide](#new-installation)

### Path B: I'm Upgrading from v3.7.x (10 minutes)
**Start here:** [Upgrade Guide](#upgrading-from-v37x)

### Path C: I Just Need to Fix Certificate Verification (5 minutes)
**Start here:** [Quick Fix Guide](#quick-fix-certificate-verification-only)

---

## 📚 Document Guide

### Essential Documents (Read These First)
1. **PACKAGE-SUMMARY.md** ← Start here (10 min read)
   - Complete overview of everything
   - Feature comparison
   - What's changed
   
2. **README.md** (5 min read)
   - Quick reference guide
   - System requirements
   - Basic troubleshooting

### Installation Documents (Choose Based on Your Needs)
3. **INSTALLATION-GUIDE-v3.8.0.md** (15 min read)
   - Step-by-step installation
   - Detailed troubleshooting
   - Configuration options
   - Maintenance procedures

4. **CHANGELOG-v3.8.0.md** (10 min read)
   - Complete list of changes
   - Technical details
   - Known issues
   - Future features

### Quick Reference Documents
5. **CERTIFICATE-FIX-IMPLEMENTATION-GUIDE.md**
   - Original fix documentation
   - Problem explanation
   - Solution details

### Installation Tools
6. **database-setup.sql**
   - Run in phpMyAdmin for manual database setup
   - Use if automatic creation fails

7. **install.sh**
   - Bash script for automated installation
   - For command-line users

---

## 🆕 New Installation

### Prerequisites
- WordPress 5.0+ installed
- PHP 7.2+ 
- MySQL 5.6+
- Admin access to WordPress
- FTP/File Manager access
- phpMyAdmin access (optional but recommended)

### Step 1: Prepare (2 minutes)

**Create Backup:**
```bash
# Even though this is new, backup your WordPress first
# Export database via phpMyAdmin
# Download /wp-content/ folder
```

**Download Package:**
- You already have this - good!
- Extract if zipped

### Step 2: Upload Plugin Files (5 minutes)

**Via FTP/File Manager:**

1. **Navigate to:** `/wp-content/plugins/`

2. **Create folder:** `mtti-mis`

3. **Upload these folders to `/wp-content/plugins/mtti-mis/`:**
   - `admin/` (entire folder)
   - `includes/` (entire folder)
   - `assets/` (entire folder)
   - `public/` (entire folder)

4. **Upload this file to `/wp-content/plugins/mtti-mis/`:**
   - `mtti-mis.php`

**Folder structure should look like:**
```
/wp-content/plugins/mtti-mis/
├── admin/
├── includes/
├── assets/
├── public/
└── mtti-mis.php
```

### Step 3: Upload Verification Page (2 minutes)

**Important:** This file goes in your website ROOT, NOT in wp-content!

1. **Navigate to:** Your website root (where `wp-config.php` is)

2. **Upload:** `verify-certificate-custom.php`

**Verification:**
- File should be at: `/public_html/verify-certificate-custom.php`
- Same level as: `wp-config.php`

### Step 4: Activate Plugin (2 minutes)

1. Go to: **WordPress Admin > Plugins**
2. Find: **MTTI Management Information System**
3. Click: **Activate**
4. Wait: System will create database tables automatically

### Step 5: Verify Installation (5 minutes)

**Check 1: Database Table**
- Open phpMyAdmin
- Run: `SHOW TABLES LIKE 'wp_mtti_certificates';`
- Should see: `wp_mtti_certificates` table

**Check 2: Plugin Active**
- WordPress Admin > Plugins
- MTTI MIS should be active (not red/deactivated)

**Check 3: Menu Items**
- Look for "MTTI MIS" in WordPress admin sidebar
- Click it - should see dashboard

**Check 4: Verification Page**
- Visit: `https://yourdomain.com/verify-certificate-custom.php`
- Should see: Clean verification form (not 404)

### Step 6: Generate Test Certificate (3 minutes)

1. **Add Test Student (if needed):**
   - MTTI MIS > Students > Add New
   - Fill basic info, save

2. **Add Test Course (if needed):**
   - MTTI MIS > Courses > Add New
   - Fill basic info, save

3. **Generate Certificate:**
   - MTTI MIS > Certificates
   - Select student
   - Click "Generate Certificate"
   - Fill form (course, grade, date)
   - Submit

4. **Verify Display:**
   - PDF should open
   - Check footer shows:
     - Certificate Number (left)
     - Verification Code (center)
     - Issue Date (right)

### Step 7: Test Verification (2 minutes)

1. **Copy Certificate Number** from PDF (e.g., MTTI/CERT/2025/123456)

2. **Go to:** `https://yourdomain.com/verify-certificate-custom.php`

3. **Enter:** Certificate number

4. **Click:** Verify Certificate

5. **Expected Result:** ✅ Green box showing all certificate details

6. **Test Again with Verification Code:**
   - Enter the verification code instead
   - Should also work!

### 🎉 Success!

If all checks passed, you're done! The system is fully operational.

**Next Steps:**
- Configure system settings
- Add your students
- Add your courses
- Generate real certificates

---

## 🔄 Upgrading from v3.7.x

### Prerequisites
- Existing MTTI MIS v3.7.x installation
- Backup capability
- FTP/File Manager access

### Step 1: Backup (5 minutes)

**Critical: Do NOT skip this!**

**Backup Files:**
```bash
# Via FTP: Download entire folder
/wp-content/plugins/mtti-mis/
```

**Backup Database:**
```sql
-- Run in phpMyAdmin
-- Select your database
-- Click "Export"
-- Save file
```

### Step 2: Identify Update Method (1 minute)

**Method A: Minimal Update (Recommended)**
- Replace only 3 files that changed
- Fastest and safest
- **Choose this if:** You want minimal risk

**Method B: Complete Replacement**
- Replace entire plugin folder
- Clean slate approach
- **Choose this if:** Having issues with current installation

### Step 3A: Minimal Update (5 minutes)

**Replace These 3 Files:**

1. **File 1:**
   - Local: `mtti-mis.php`
   - Upload to: `/wp-content/plugins/mtti-mis/mtti-mis.php`
   - Overwrite: Yes

2. **File 2:**
   - Local: `includes/class-mtti-mis-activator.php`
   - Upload to: `/wp-content/plugins/mtti-mis/includes/class-mtti-mis-activator.php`
   - Overwrite: Yes

3. **File 3:**
   - Local: `admin/class-mtti-mis-admin-certificates.php`
   - Upload to: `/wp-content/plugins/mtti-mis/admin/class-mtti-mis-admin-certificates.php`
   - Overwrite: Yes

**Add New File:**

4. **Verification Page:**
   - Local: `verify-certificate-custom.php`
   - Upload to: `/public_html/verify-certificate-custom.php` (website root)
   - This is a NEW file (doesn't exist yet)

### Step 3B: Complete Replacement (10 minutes)

1. **Deactivate Plugin:**
   - WordPress Admin > Plugins
   - MTTI MIS > Deactivate

2. **Delete Old Folder:**
   - Via FTP: Delete `/wp-content/plugins/mtti-mis/`
   - Or rename to: `/wp-content/plugins/mtti-mis-old/`

3. **Upload New Folder:**
   - Upload entire `admin/`, `includes/`, `assets/`, `public/` folders
   - Upload `mtti-mis.php`

4. **Upload Verification Page:**
   - Upload `verify-certificate-custom.php` to website root

### Step 4: Reactivate Plugin (2 minutes)

1. **WordPress Admin > Plugins**

2. **Find MTTI MIS**

3. **Click "Activate"**

4. **Wait for Confirmation:**
   - Should see "Plugin activated" message
   - If error, check error message

5. **Check Database:**
   ```sql
   SHOW TABLES LIKE 'wp_mtti_certificates';
   ```
   - Should return the new table
   - If not, run `database-setup.sql` manually

### Step 5: Test Everything (5 minutes)

**Test 1: Old Features Still Work**
- Check students list
- Check courses list
- Check payments
- Everything should work as before

**Test 2: New Certificate System**
- Generate a certificate
- Check it shows verification code
- Check database entry exists
- Test verification page

**Test 3: Old Certificates**
- Old certificates (before upgrade) won't be verifiable
- This is expected behavior
- Need to regenerate or manually add to database

### 🎉 Upgrade Complete!

Your system now has certificate verification!

**Important Notes:**
- Old certificates need regeneration to be verifiable
- All new certificates will automatically be verifiable
- No data was lost during upgrade

---

## ⚡ Quick Fix: Certificate Verification Only

**Use this if:**
- You already have MTTI MIS installed
- Only certificate verification is broken
- Don't want to update everything

### Fix in 3 Steps (5 minutes)

**Step 1: Create Database Table**
```sql
-- Run in phpMyAdmin
-- Copy entire contents of database-setup.sql
-- Paste and execute
```

**Step 2: Update Certificate Class**
```
-- Replace this file:
/wp-content/plugins/mtti-mis/admin/class-mtti-mis-admin-certificates.php

-- With:
admin/class-mtti-mis-admin-certificates.php (from package)
```

**Step 3: Add Verification Page**
```
-- Upload:
verify-certificate-custom.php

-- To:
/public_html/ (website root)
```

**Test:**
- Generate new certificate
- Should now verify successfully

---

## 🆘 Troubleshooting

### Installation Issues

**Problem: Plugin won't activate**
```
Solutions:
1. Check PHP version: php -v (need 7.2+)
2. Check file permissions: 755 for folders, 644 for files
3. Check error logs: /wp-content/debug.log
4. Try renaming plugin folder and re-uploading
```

**Problem: White screen after activation**
```
Solutions:
1. Enable debug mode in wp-config.php
2. Check error logs
3. Deactivate other plugins temporarily
4. Increase PHP memory limit
```

**Problem: Certificates table not created**
```
Solutions:
1. Run database-setup.sql manually in phpMyAdmin
2. Check database user permissions
3. Check WordPress table prefix (wp_ or custom)
4. Try deactivate/reactivate plugin
```

### Certificate Generation Issues

**Problem: Certificate generates but no verification code**
```
Solutions:
1. Clear browser cache
2. Verify correct file was uploaded
3. Regenerate certificate
4. Check for PHP errors in debug.log
```

**Problem: Certificate not saving to database**
```
Solutions:
1. Check table exists: SHOW TABLES LIKE 'wp_mtti_certificates';
2. Check debug.log for errors
3. Verify database write permissions
4. Try manual database entry (see EMERGENCY-CERTIFICATE-ADD.sql)
```

### Verification Issues

**Problem: Verification page shows 404**
```
Solutions:
1. Verify file is in website root (not wp-content)
2. Check file permissions: chmod 644
3. Check .htaccess not blocking PHP files
4. Try accessing: /verify-certificate-custom.php directly
```

**Problem: Verification always returns "Not Found"**
```
Solutions:
1. Generate new certificate
2. Check database has entries: SELECT * FROM wp_mtti_certificates;
3. Verify certificate number matches exactly (case-sensitive)
4. Check WordPress connection in verification file
```

---

## 📞 Getting Additional Help

### Self-Help Resources

1. **Read Documentation:**
   - PACKAGE-SUMMARY.md - Complete overview
   - INSTALLATION-GUIDE-v3.8.0.md - Detailed guide
   - README.md - Quick reference

2. **Enable Debug Mode:**
   ```php
   // Add to wp-config.php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

3. **Check Logs:**
   - `/wp-content/debug.log` - WordPress errors
   - Server error logs (ask hosting provider)

### Before Contacting Support

Gather this information:
- WordPress version
- PHP version (from Site Health)
- Database version
- Exact error messages
- Steps to reproduce
- Screenshots

---

## ✅ Installation Checklist

### Pre-Installation
- [ ] Read PACKAGE-SUMMARY.md
- [ ] WordPress backup completed
- [ ] Database backup completed
- [ ] System requirements verified

### During Installation
- [ ] Plugin files uploaded
- [ ] Verification page uploaded to root
- [ ] Plugin activated successfully
- [ ] No error messages shown

### Post-Installation Verification
- [ ] Database table exists
- [ ] Admin menu shows MTTI MIS
- [ ] Test certificate generated
- [ ] Certificate shows verification code
- [ ] Certificate saved to database
- [ ] Verification page accessible
- [ ] Verification works correctly
- [ ] No errors in debug.log

### Production Readiness
- [ ] All tests passed
- [ ] Documentation reviewed
- [ ] Team trained on new features
- [ ] Support contacts ready
- [ ] Monitoring in place

---

## 🎯 Next Steps After Installation

### Immediate (Day 1)
1. Configure system settings
2. Set up user roles
3. Add test data
4. Train admin users
5. Test all features

### Short Term (Week 1)
1. Add actual students
2. Add actual courses
3. Generate test certificates
4. Verify certificates work
5. Document any customizations

### Ongoing
1. Regular database backups
2. Monitor certificate generation
3. Check verification logs
4. Update documentation
5. Train new staff

---

## 📊 Quick Reference

### Important URLs
- Admin Dashboard: `/wp-admin/`
- MTTI MIS Dashboard: `/wp-admin/admin.php?page=mtti-mis`
- Certificates: `/wp-admin/admin.php?page=mtti-mis-certificates`
- Verification Page: `/verify-certificate-custom.php`

### Important Files
- Main Plugin: `wp-content/plugins/mtti-mis/mtti-mis.php`
- Certificates Admin: `wp-content/plugins/mtti-mis/admin/class-mtti-mis-admin-certificates.php`
- Database Setup: `wp-content/plugins/mtti-mis/includes/class-mtti-mis-activator.php`
- Verification Page: `verify-certificate-custom.php` (in root)

### Important Tables
- Students: `wp_mtti_students`
- Courses: `wp_mtti_courses`
- Certificates: `wp_mtti_certificates`
- Payments: `wp_mtti_payments`

### Support Contacts
- Website: https://mtti.ac.ke
- Email: info@mtti.ac.ke
- Phone: (add phone)

---

**Version:** 3.8.0  
**Last Updated:** December 12, 2024  
**Document Type:** Getting Started Guide

**Good luck with your installation! 🚀**
