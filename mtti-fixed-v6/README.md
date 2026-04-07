# MTTI MIS v3.8.0 - Certificate Verification System

## 🎯 Overview

This is an updated version of the MTTI Management Information System WordPress plugin that adds **certificate verification functionality**. This update solves the problem where certificates were generated but could not be verified online because they weren't being saved to the database.

## ✨ What's New in v3.8.0

### Certificate Verification System
- ✅ Certificates are now saved to database when generated
- ✅ Each certificate gets a unique verification code
- ✅ Online verification page for certificate authentication
- ✅ Verify by certificate number OR verification code
- ✅ Professional certificate layout with verification details

### Key Features
- 🔍 **Dual Verification**: Certificate number OR verification code
- 📊 **Database Storage**: All certificates stored for verification
- 🎨 **Enhanced Design**: Professional certificate layout with verification info
- 🔐 **Status Management**: Valid, Revoked, or Expired status
- 📱 **Responsive**: Verification page works on all devices
- 🚀 **Future Ready**: QR code infrastructure in place

## 📦 Package Contents

```
mtti-mis-v3.8.0/
├── README.md (this file)
├── CHANGELOG-v3.8.0.md
├── INSTALLATION-GUIDE-v3.8.0.md
├── mtti-mis.php (updated main plugin file)
├── class-mtti-mis-activator.php (updated - creates certificates table)
├── class-mtti-mis-admin-certificates.php (updated - saves to database)
└── verify-certificate-custom.php (new - verification page)
```

## 🚀 Quick Installation

### For First-Time Users
1. Upload entire `mtti-mis` folder to `/wp-content/plugins/`
2. Upload `verify-certificate-custom.php` to your website root
3. Activate plugin in WordPress Admin
4. Done!

### For Existing Users (Upgrading from v3.7.x)
1. **BACKUP FIRST!** (Database + Files)
2. Deactivate current MTTI MIS plugin
3. Replace these 3 files:
   - `wp-content/plugins/mtti-mis/mtti-mis.php`
   - `wp-content/plugins/mtti-mis/includes/class-mtti-mis-activator.php`
   - `wp-content/plugins/mtti-mis/admin/class-mtti-mis-admin-certificates.php`
4. Upload `verify-certificate-custom.php` to website root
5. Reactivate plugin
6. Test certificate generation and verification

## 📝 How It Works

### Certificate Generation (Admin Side)
1. Admin goes to MTTI MIS > Certificates
2. Selects student and generates certificate
3. System automatically:
   - Creates certificate PDF
   - Generates unique certificate number (e.g., MTTI/CERT/2025/123456)
   - Generates verification code (e.g., ABCD-EFGH-JKLM)
   - Saves all data to database
   - Displays certificate with verification details

### Certificate Verification (Public Side)
1. Anyone visits: `https://yourdomain.com/verify-certificate-custom.php`
2. Enters either:
   - Certificate Number: MTTI/CERT/2025/123456
   - OR Verification Code: ABCD-EFGH-JKLM
3. System checks database and displays:
   - ✅ Valid: Shows all certificate details
   - ❌ Not Found: Certificate doesn't exist
   - ⚠️ Revoked: Certificate was cancelled

## 🔧 Technical Details

### Database Table: `wp_mtti_certificates`

| Column | Type | Description |
|--------|------|-------------|
| certificate_id | bigint(20) | Primary key |
| certificate_number | varchar(100) | Unique cert number |
| verification_code | varchar(50) | Unique 12-char code |
| student_id | bigint(20) | Foreign key to students |
| student_name | varchar(255) | Student full name |
| admission_number | varchar(50) | Student admission no |
| course_id | bigint(20) | Foreign key to courses |
| course_name | varchar(255) | Course name |
| course_code | varchar(50) | Course code |
| grade | varchar(50) | Grade achieved |
| completion_date | date | Course completion |
| issue_date | date | Certificate issue date |
| status | varchar(20) | Valid/Revoked/Expired |
| notes | text | Additional notes |
| created_at | datetime | Record creation |
| updated_at | datetime | Last update |

### Files Modified

**1. mtti-mis.php**
- Updated version to 3.8.0
- Updated database version check

**2. includes/class-mtti-mis-activator.php**
- Added certificates table creation
- Added table indexes for performance

**3. admin/class-mtti-mis-admin-certificates.php**
- Added `generate_verification_code()` method
- Added `save_certificate_to_database()` method
- Updated `create_certificate_pdf()` to save data
- Updated certificate layout to show verification code

**4. verify-certificate-custom.php** (NEW)
- Standalone verification page
- Responsive design matching MTTI branding
- Dual search (certificate number or code)
- Status indicators (Valid/Revoked/Not Found)

## 🎨 Certificate Layout

```
┌──────────────────────────────────────────┐
│          MTTI Logo                       │
│                                          │
│    CERTIFICATE OF COMPLETION             │
│  Masomotele Technical Training Institute │
│                                          │
│         This is to certify that          │
│         [Student Name]                   │
│  has successfully completed the course   │
│         [Course Name]                    │
│                                          │
│    Details: Admission No, Grade, etc.    │
│                                          │
│    [Signatures]                          │
│                                          │
│  "Start Learning, Start Earning"         │
│                                          │
│  Cert No:        Verification:    Date:  │
│  MTTI/CERT/     ABCD-EFGH-       Dec 12, │
│  2025/123456    JKLM             2025    │
└──────────────────────────────────────────┘
```

## 🧪 Testing Your Installation

### Test 1: Database
```sql
-- Run in phpMyAdmin
SHOW TABLES LIKE 'wp_mtti_certificates';
-- Should return: wp_mtti_certificates
```

### Test 2: Generate Certificate
1. Admin > MTTI MIS > Certificates
2. Generate test certificate
3. Check PDF shows: Cert No + Verification Code + Date

### Test 3: Verify Certificate
1. Visit: `https://yourdomain.com/verify-certificate-custom.php`
2. Enter certificate number from test 2
3. Should show: Green success box with details

### Test 4: Database Entry
```sql
-- Run in phpMyAdmin
SELECT * FROM wp_mtti_certificates ORDER BY certificate_id DESC LIMIT 1;
-- Should show: Your test certificate data
```

## 🔍 Troubleshooting

### Common Issues

**1. Certificate Not Found**
- Problem: Verification returns "not found"
- Solution: Check database table exists, regenerate certificate

**2. Verification Page 404**
- Problem: Can't access verification page
- Solution: Ensure file is in website root, check permissions

**3. No Verification Code on Certificate**
- Problem: Certificate shows but no code
- Solution: Verify file updates applied, clear cache, regenerate

**4. Table Creation Failed**
- Problem: Plugin activates but table not created
- Solution: Run SQL manually (see INSTALLATION-GUIDE)

### Enable Debug Mode
```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// Check: /wp-content/debug.log
```

## 📚 Documentation

### Complete Guides Included
- **CHANGELOG-v3.8.0.md** - All changes and features
- **INSTALLATION-GUIDE-v3.8.0.md** - Detailed installation instructions
- **README.md** - This file (quick reference)

### Additional Resources
- Database schema details
- Customization guide
- Security considerations
- Maintenance procedures
- Backup strategies

## ⚠️ Important Notes

### For Existing Installations
- **Old certificates won't be verifiable** - They weren't saved to database
- Options:
  1. Regenerate all old certificates (recommended)
  2. Manually add old certificates to database (see guide)
  
### System Requirements
- WordPress 5.0+
- PHP 7.2+
- MySQL 5.6+
- Write permissions for plugin directory

### Security
- Verification page has no authentication (public access)
- Prevent XSS with proper escaping (already implemented)
- Regular database backups recommended
- Monitor for SQL injection attempts

## 🔮 Future Features (v3.9.0)

- [ ] QR Code generation on certificates
- [ ] Certificate revocation interface
- [ ] Bulk certificate operations
- [ ] Email notifications
- [ ] Certificate analytics dashboard
- [ ] PDF download with QR code
- [ ] Custom certificate templates
- [ ] Multi-language support

## 📞 Support

### Before Requesting Help
1. Read INSTALLATION-GUIDE-v3.8.0.md
2. Check CHANGELOG-v3.8.0.md for known issues
3. Enable debug logging
4. Test with default WordPress theme

### Information Needed
- WordPress version
- PHP version
- Database version
- Error messages from debug.log
- Steps to reproduce issue

## 📄 License

GPL-2.0+ - Same as WordPress
Copyright (c) 2024 Masomotele Technical Training Institute

## 👥 Credits

**Developed for:**
Masomotele Technical Training Institute (M.T.T.I)
Sagaas Center, Fourth Floor
Eldoret, Kenya

**Plugin Version:** 3.8.0  
**Release Date:** December 2024  
**Database Version:** 3.8.0

---

## 🎓 About MTTI

Masomotele Technical Training Institute offers practical, short-term technical courses in:
- Computer Applications
- Web Development
- Graphic Design
- Digital Marketing
- Cybersecurity
- Mobile Repair

**Motto:** "Start Learning, Start Earning"

---

## ✅ Quick Checklist

Installation:
- [ ] Files uploaded
- [ ] Plugin activated
- [ ] Database table created
- [ ] Verification page accessible

Testing:
- [ ] Certificate generated
- [ ] Shows verification code
- [ ] Saved to database
- [ ] Verification works
- [ ] No errors in logs

Production Ready:
- [ ] Backup completed
- [ ] Documentation reviewed
- [ ] All tests passed
- [ ] Team trained

---

**Need help?** Check INSTALLATION-GUIDE-v3.8.0.md for detailed instructions.

**Found a bug?** Enable debug logging and check error messages.

**Want to customize?** See customization section in installation guide.
