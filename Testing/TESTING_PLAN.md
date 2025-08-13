# Pic Pilot - Inline Conversion Testing Plan

## 🧪 Testing Environment Setup

**Branch**: `inline-conversion` (current development)  
**Base**: `png-to-jpeg-2` (stable with backup system)  
**Test Database**: Fresh WordPress installation with sample images  
**WordPress Version**: 6.x latest  
**PHP Version**: 8.1+  

## 📋 Pre-Testing Checklist

- [ ] Plugin activated successfully
- [ ] No PHP errors in error log
- [ ] JavaScript console shows "📱 Pic Pilot Media Converter initialized"
- [ ] CSS styles loading correctly
- [ ] Format Options column visible in Media Library
- [ ] Conversion buttons appear on attachment edit pages

## 🎯 Test Categories

### **Category A: Core Functionality Tests (15 scenarios)**

#### A1: JPEG → PNG Conversion
- [ ] **A1.1**: Basic JPEG to PNG conversion with backup creation
- [ ] **A1.2**: Large JPEG (>2MB) conversion performance
- [ ] **A1.3**: Small JPEG (<100KB) conversion accuracy
- [ ] **A1.4**: JPEG with EXIF data preservation check
- [ ] **A1.5**: Batch JPEG to PNG conversion (5+ images)

**Expected Results**: 
- Successful format conversion
- Automatic backup creation (conversion type)
- File size increase (PNG typically larger than JPEG)
- EXIF data preserved where possible
- No PHP errors or timeouts

#### A2: PNG → JPEG Conversion
- [ ] **A2.1**: Opaque PNG to JPEG conversion
- [ ] **A2.2**: PNG with transparency (should show warning/disabled button)
- [ ] **A2.3**: PNG with alpha channel detection
- [ ] **A2.4**: Large PNG (>5MB) optimization and conversion
- [ ] **A2.5**: Batch PNG to JPEG conversion

**Expected Results**:
- Transparency detection working correctly
- Significant file size reduction for opaque PNGs
- Warning/disabled button for transparent PNGs
- Quality settings respected

#### A3: WebP Conversions
- [ ] **A3.1**: JPEG to WebP with quality settings (80%)
- [ ] **A3.2**: PNG to WebP with transparency preservation
- [ ] **A3.3**: WebP to original format restoration
- [ ] **A3.4**: Batch WebP conversion (mixed formats)
- [ ] **A3.5**: WebP quality comparison (60%, 80%, 95%)

**Expected Results**:
- WebP files significantly smaller than originals
- Transparency preserved for PNG→WebP
- Quality settings produce expected file sizes
- Restoration works correctly

### **Category B: Integration Tests (15 scenarios)**

#### B1: Media Library Integration
- [ ] **B1.1**: Conversion buttons visible in Media Library grid view
- [ ] **B1.2**: Buttons functional in Media Library list view
- [ ] **B1.3**: Format Options column displays correctly
- [ ] **B1.4**: Bulk selection and batch operations work
- [ ] **B1.5**: Progress indicators display during conversion

**Expected Results**:
- UI elements display correctly across all media library views
- Buttons respond to clicks
- Progress indicators show and hide appropriately
- No layout issues or overlapping elements

#### B2: Backup System Integration
- [ ] **B2.1**: Automatic backup creation for all conversions
- [ ] **B2.2**: Multiple backup types coexist (user, conversion, serving)
- [ ] **B2.3**: Restoration from inline conversions works
- [ ] **B2.4**: Backup cleanup on attachment deletion
- [ ] **B2.5**: Chain conversion tracking (PNG→JPEG→WebP)

**Expected Results**:
- All conversions create appropriate backups
- RestoreManager detects and uses correct backup types
- No backup conflicts or overwrites
- Complete cleanup on deletion

#### B3: Upload Processing Compatibility
- [ ] **B3.1**: Upload mode settings don't conflict with inline conversions
- [ ] **B3.2**: Conversion priority: inline vs automatic upload processing
- [ ] **B3.3**: Settings inheritance and override behavior
- [ ] **B3.4**: Processing queue management for simultaneous operations
- [ ] **B3.5**: Error state handling and recovery

**Expected Results**:
- No conflicts between upload and inline processing
- Clear priority rules followed
- Settings behave predictably
- Graceful error handling

### **Category C: Edge Cases & Error Handling (15 scenarios)**

#### C1: Error Scenarios  
- [ ] **C1.1**: Corrupted image file conversion attempt
- [ ] **C1.2**: Insufficient disk space during conversion
- [ ] **C1.3**: File permission errors
- [ ] **C1.4**: Server timeout during large batch operations
- [ ] **C1.5**: Concurrent conversion attempts on same image

**Expected Results**:
- Graceful error messages displayed
- No PHP fatal errors
- Partial conversions rolled back properly
- User-friendly error explanations

#### C2: WordPress Compatibility
- [ ] **C2.1**: Elementor page builder image updates
- [ ] **C2.2**: Gutenberg block editor image references
- [ ] **C2.3**: Classic editor image links
- [ ] **C2.4**: Theme compatibility (Astra, Twenty Twenty-Four)
- [ ] **C2.5**: Plugin conflicts (other optimization plugins)

**Expected Results**:
- Content references updated correctly
- No broken images after conversion
- Page builders recognize format changes
- Minimal conflicts with other plugins

#### C3: Performance & Scale
- [ ] **C3.1**: Large batch operations (50+ images)
- [ ] **C3.2**: Memory usage monitoring during conversions
- [ ] **C3.3**: Server timeout handling and recovery
- [ ] **C3.4**: Progress tracking accuracy for large operations
- [ ] **C3.5**: Cancellation functionality works correctly

**Expected Results**:
- No memory exhaustion errors
- Timeouts handled gracefully
- Progress tracking accurate within 5%
- Clean cancellation without orphaned files

## 🛠️ Testing Tools & Helpers

### Testing Helper Functions
```php
// Add to functions.php for testing
function pic_pilot_test_log($message) {
    error_log("[PIC PILOT TEST] " . $message);
}

function pic_pilot_verify_backup($attachment_id) {
    $backup_info = \PicPilot\Backup\SmartBackupManager::get_backup_info($attachment_id);
    return !empty($backup_info);
}

function pic_pilot_test_conversion($attachment_id, $conversion_type) {
    // Test conversion and return detailed results
    $result = \PicPilot\Admin\MediaLibraryConverter::perform_conversion($attachment_id, $conversion_type);
    pic_pilot_test_log("Conversion $conversion_type for ID $attachment_id: " . ($result['success'] ? 'SUCCESS' : 'FAILED'));
    return $result;
}
```

### Browser Testing Checklist
- [ ] Chrome (latest)
- [ ] Firefox (latest)  
- [ ] Safari (if Mac available)
- [ ] Mobile responsive testing
- [ ] JavaScript console errors
- [ ] Network tab for AJAX requests

### Performance Monitoring
- [ ] PHP memory usage
- [ ] MySQL query count
- [ ] File system operations
- [ ] AJAX response times
- [ ] Error log monitoring

## 📊 Test Results Template

### Test Execution Log
```
Test ID: A1.1
Date: 2025-08-12
Tester: [Name]
Environment: Local/Staging/Production

Steps Executed:
1. Uploaded test JPEG image (image1.jpg, 150KB)
2. Clicked JPEG → PNG conversion button
3. Monitored progress indicator
4. Verified conversion completion

Results:
✅ Conversion completed successfully
✅ Backup created in /conversion/ directory
✅ File format changed to PNG
✅ File size increased to 280KB (expected)
⚠️ EXIF data partially preserved (investigate)
❌ Progress indicator disappeared too quickly

Notes:
- Conversion took 3.2 seconds
- Memory usage: 45MB peak
- No PHP errors logged
- UI responsiveness good

Pass/Fail: PASS (with minor UI issue)
```

## 🚨 Critical Test Points

### Must-Pass Scenarios
1. **Basic conversion functionality** - All format conversions must work
2. **Backup creation** - Every conversion must create appropriate backup
3. **Restoration capability** - All conversions must be reversible
4. **No data loss** - Original images must be recoverable
5. **Error handling** - No PHP fatal errors under any circumstances

### Performance Benchmarks
- Single conversion: < 5 seconds for images < 2MB
- Batch conversion: < 30 seconds for 10 images
- Memory usage: < 100MB for typical operations
- No timeouts on standard hosting environments

### Security Requirements
- All AJAX endpoints require proper nonces
- User capability checks enforced
- File operations properly validated
- No arbitrary code execution vulnerabilities

## 🔄 Regression Testing

Before each release, run core functionality tests:
- [ ] Upload processing still works
- [ ] Existing backup/restore system functional
- [ ] Settings page loads and saves correctly
- [ ] Bulk optimization features intact
- [ ] No conflicts with existing features

## 📝 Bug Reporting Template

```
**Bug ID**: INL-001
**Severity**: High/Medium/Low
**Category**: Functionality/UI/Performance/Security

**Description**: Brief description of the issue

**Steps to Reproduce**:
1. Step one
2. Step two
3. Step three

**Expected Result**: What should happen
**Actual Result**: What actually happened

**Environment**:
- WordPress Version: 6.x
- PHP Version: 8.x
- Browser: Chrome 119
- Plugin Version: 0.1.2

**Screenshots**: [Attach if applicable]
**Error Logs**: [Include relevant logs]
**Workaround**: [If known]
```

## ✅ Testing Completion Checklist

### Phase 1 Complete When:
- [ ] All Category A tests pass (Core Functionality)
- [ ] No critical bugs in Category B (Integration)
- [ ] Major edge cases handled (Category C)
- [ ] Performance benchmarks met
- [ ] Security review completed
- [ ] Documentation updated
- [ ] User acceptance testing completed

### Release Readiness Criteria:
- [ ] 95%+ test pass rate
- [ ] Zero critical/high severity bugs
- [ ] Performance within acceptable limits
- [ ] Cross-browser compatibility verified
- [ ] Accessibility requirements met (basic)
- [ ] Plugin update/activation testing completed

---

**Note**: This testing plan should be executed in a controlled environment first, then staging, before any production deployment.