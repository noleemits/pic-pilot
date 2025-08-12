# Claude Instructions for Pic Pilot WordPress Plugin

## Project Overview
Pic Pilot is a WordPress plugin for image optimization and compression. It supports:
- **WebP conversion** (primary focus) - PNG/JPEG/GIF → WebP
- **PNG to JPEG conversion** (compression mode only)
- **Image compression** via multiple engines (local, TinyPNG)
- **Advanced backup and restore system** with smart operation detection
- **Bulk optimization** with real-time progress tracking
- **Upload processing modes**: Disabled, Compress Same Formats, Convert to WebP

## Key File Structure
- `pic-pilot.php` - Main plugin file with attachment deletion hooks
- `includes/` - Core plugin classes
  - `Settings.php` - Settings management with capability detection
  - `WebPConverter.php` - WebP conversion using Imagick/GD
  - `Upload/UploadProcessor.php` - Upload processing with mode handling
  - `Admin/SettingsPage.php` - Settings UI with quality dropdowns
  - `Admin/LogViewer.php` - Log viewer with copy-to-clipboard
  - `Backup/` - Advanced backup and restore system
    - `SmartBackupManager.php` - Operation-aware backup creation
    - `RestoreManager.php` - Central restoration coordinator
    - `Handlers/` - Specific restore handlers for different operations
  - `Compressor/` - Image compression engines
  - `Optimizer.php` - Manual optimization logic

## Development Commands
- No specific build commands - standard PHP/WordPress development
- WordPress coding standards apply
- Test with Elementor, Classic Editor, and Astra theme

## Current Branch
- Working on: `png-to-jpeg-2`
- **Current Status**: Core functionality complete, restoration system fixed

## Recent Major Updates (2025-08-10)

### **Phase 1: Settings Simplification Complete**
- **Conversion Mode**: Simplified to WebP-only (removed PNG→JPEG options from conversion mode)
- **Quality Settings**: Replaced input fields with predefined dropdown options (8 levels: 100,95,85,80,75,70,65,60)
- **WebP Conversion**: Made automatic when conversion mode selected (removed redundant checkbox)
- **UI Fixes**: Fixed WebP message displaying outside settings area, improved warnings

### **Advanced Backup System Implementation**
- **Two-Tier Architecture**: Legacy system + Smart Backup System
- **Smart Backup Types**:
  - **Conversion Backups**: Auto-created for format changes (PNG→WebP, JPEG→WebP) - always enabled
  - **User Backups**: Created for compression operations - user controlled
  - **Serving Backups**: For browser compatibility (future use)

### **Restoration System Fixes**
- **Fixed Re-conversion Issue**: Added `$pic_pilot_restoring` flag to prevent immediate re-conversion after restore
- **Fixed File Duplication**: Implemented filename normalization to remove `-resized` suffixes during restore
- **Improved Cleanup**: Fixed attachment deletion to clean up both legacy and smart backup systems
- **Thumbnail Consistency**: Ensured thumbnail names match normalized main file names

### **Current Functionality Status**
**✅ Working Correctly**:
- WebP conversion with quality dropdowns (primary feature)
- Backup creation for all operation types
- Restoration without re-conversion or file duplication
- Upload processing modes (Disabled, Compress, Convert)
- PNG→JPEG conversion in compression mode
- Complete file cleanup on deletion
- Quality warnings for resize→conversion workflow

**⚙️ Architecture Improvements**:
- Operation-aware backup decisions
- Centralized restoration coordinator
- Handler-based restoration system
- Filename normalization during restore
- Complete backup cleanup on deletion

## Next Development Priorities

### **Immediate Tasks (Next Session)**
1. **Apply Normalization Fix to Other Handlers**: Update `CompressionRestoreHandler.php` with same filename normalization logic
2. **Test Complete Workflow**: Upload → Convert → Optimize → Restore → Delete cycle verification
3. **Settings UI Polish**: Review and improve setting dependencies and conditional display

### **Future Enhancements**
1. **TinyPNG WebP Support**: Implement TinyPNG API for WebP conversion (currently local only)
2. **Bulk Restore Feature**: Add bulk restore capability to backup manager
3. **Performance Optimizations**: 
   - Optimize backup file size (differential backups?)
   - Add backup expiration automation
   - Implement async processing for large files
4. **Advanced Features**:
   - WebP serving fallback system for unsupported browsers
   - Progressive JPEG support
   - AVIF format support (next-gen after WebP)

### **Known Limitations**
- **Resize Quality Warning**: Resize before WebP conversion reduces quality (documented with UI warnings)
- **TinyPNG WebP**: Currently falls back to local conversion
- **Compression Handler**: May still have filename normalization issues

## Technical Architecture

### **Smart Backup Decision Logic**
```php
// Auto-created (always)
'convert_to_webp', 'convert_png_to_jpeg' → Conversion backup

// User-controlled (settings dependent)  
'compress_jpeg', 'compress_png', 'optimize_image' → User backup

// Conditional (serving method dependent)
'browser_serving_prep' → Serving backup
```

### **Upload Processing Modes**
- **Disabled**: No automatic processing
- **Compress**: JPEG→JPEG, PNG→PNG optimization (same format)
- **Convert**: PNG/JPEG/GIF → WebP conversion (format change)

### **Restoration Priority**
1. Most recent backup type (usually user backup if manual optimize was done)
2. Conversion backup (preserves original format)
3. Legacy backup (compatibility)

### **File Naming Strategy**
- Original upload: `image.jpg`
- With resize: `image-resized.jpg` (when keep original enabled)
- During restore: Normalize to `image.jpg` (remove `-resized` suffix)
- Thumbnails: Match main file base name for consistency

## Security & Standards
- WordPress coding standards compliance
- Proper sanitization and validation
- WordPress nonces for form submissions
- Safe file handling with proper cleanup
- Server capability detection prevents unsupported operations