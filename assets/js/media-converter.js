/**
 * Media Library Converter JavaScript
 * Handles inline format conversions in WordPress Media Library
 */

(function($) {
    'use strict';
    
    const MediaConverter = {
        init: function() {
            this.bindEvents();
            this.initBulkActions();
            console.log('📱 Pic Pilot Media Converter initialized');
        },
        
        bindEvents: function() {
            // Single image conversion buttons
            $(document).on('click', '.pic-pilot-convert-btn', this.handleConversion.bind(this));
            $(document).on('click', '.pic-pilot-restore-btn', this.handleRestore.bind(this));
            $(document).on('click', '.pic-pilot-compress-btn', this.handleCompression.bind(this));
            
            // Batch conversion
            $(document).on('click', '.pic-pilot-batch-convert', this.handleBatchConversion.bind(this));
            
            // Progress monitoring
            this.progressInterval = null;
        },
        
        initBulkActions: function() {
            // Add bulk conversion options to media library
            if ($('body').hasClass('upload-php')) {
                this.addBulkConversionOptions();
            }
        },
        
        addBulkConversionOptions: function() {
            const bulkActions = $('#bulk-action-selector-top, #bulk-action-selector-bottom');
            
            if (bulkActions.length) {
                const conversionOptions = [
                    '<optgroup label="Pic Pilot Conversions">',
                    '<option value="pic_pilot_to_jpeg">Convert to JPEG</option>',
                    '<option value="pic_pilot_to_png">Convert to PNG</option>',
                    '<option value="pic_pilot_to_webp">Convert to WebP</option>',
                    '<option value="pic_pilot_compress">Compress Images</option>',
                    '</optgroup>'
                ].join('');
                
                bulkActions.append(conversionOptions);
            }
            
            // Handle bulk action submissions
            $('form#posts-filter').on('submit', this.handleBulkSubmit.bind(this));
        },
        
        handleConversion: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const attachmentId = $button.data('attachment-id');
            const conversionType = $button.data('conversion-type');
            const nonce = $button.data('nonce');
            const hasBackups = $button.data('has-backups') === 'true';
            
            // Show appropriate confirmation based on backup status
            if (!picPilotConverter.backupEnabled) {
                if (!hasBackups) {
                    // No backups exist and backup creation is disabled
                    if (!confirm(picPilotConverter.strings.confirmNoBackup)) {
                        return;
                    }
                } else {
                    // Backups exist but new ones won't be created
                    if (!confirm(picPilotConverter.strings.confirmNoNewBackups)) {
                        return;
                    }
                }
            }
            
            this.showProgress(attachmentId, 'Converting...');
            $button.prop('disabled', true);
            
            $.ajax({
                url: picPilotConverter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'pic_pilot_convert_format',
                    attachment_id: attachmentId,
                    conversion_type: conversionType,
                    nonce: nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showSuccess(attachmentId, response.data);
                        this.refreshAttachmentData(attachmentId);
                    } else {
                        this.showError(attachmentId, response.data.message);
                    }
                },
                error: (xhr) => {
                    this.showError(attachmentId, 'Network error occurred');
                },
                complete: () => {
                    $button.prop('disabled', false);
                    setTimeout(() => this.hideProgress(attachmentId), 3000);
                }
            });
        },
        
        handleRestore: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const attachmentId = $button.data('attachment-id');
            const nonce = $button.data('nonce');
            
            // Restore always shows confirmation since it's irreversible
            if (!confirm(picPilotConverter.strings.confirmRestore)) {
                return;
            }
            
            this.showProgress(attachmentId, 'Restoring...');
            $button.prop('disabled', true);
            
            // Use existing restore functionality from RestoreManager
            $.ajax({
                url: picPilotConverter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'pic_pilot_restore_attachment',
                    attachment_id: attachmentId,
                    _wpnonce: nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showSuccess(attachmentId, {message: 'Restoration completed!'});
                        // Reload page to show updated format and buttons
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        this.showError(attachmentId, response.data?.message || 'Restoration failed');
                    }
                },
                error: (xhr) => {
                    console.error('Restore error:', xhr);
                    this.showError(attachmentId, 'Restoration failed - check console for details');
                },
                complete: () => {
                    $button.prop('disabled', false);
                    setTimeout(() => this.hideProgress(attachmentId), 3000);
                }
            });
        },
        
        handleCompression: function(e) {
            e.preventDefault();
            
            const $button = $(e.currentTarget);
            const attachmentId = $button.data('attachment-id');
            const nonce = $button.data('nonce');
            
            if (!confirm('Optimize this image?')) {
                return;
            }
            
            this.showProgress(attachmentId, 'Optimizing...');
            $button.prop('disabled', true);
            
            // Use existing optimization functionality (redirect-based)
            window.location.href = picPilotConverter.ajaxUrl + 
                '?action=pic_pilot_optimize&attachment_id=' + attachmentId + 
                '&_wpnonce=' + nonce;
        },
        
        handleBatchConversion: function(e) {
            e.preventDefault();
            
            const selectedIds = this.getSelectedAttachmentIds();
            if (selectedIds.length === 0) {
                console.log('Please select images to convert');
                return;
            }
            
            const conversionType = $(e.currentTarget).data('conversion-type');
            
            if (!confirm(`Convert ${selectedIds.length} images to ${conversionType}?`)) {
                return;
            }
            
            this.showBatchProgress(selectedIds.length);
            
            $.ajax({
                url: picPilotConverter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'pic_pilot_batch_convert',
                    attachment_ids: selectedIds,
                    conversion_type: conversionType,
                    nonce: picPilotConverter.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showBatchResults(response.data);
                        location.reload(); // Refresh to show new formats
                    } else {
                        console.log('Batch conversion failed: ' + response.data.message);
                    }
                },
                error: () => {
                    console.log('Batch conversion failed');
                },
                complete: () => {
                    this.hideBatchProgress();
                }
            });
        },
        
        handleBulkSubmit: function(e) {
            const action = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();
            
            if (!action.startsWith('pic_pilot_')) {
                return; // Not our action
            }
            
            e.preventDefault();
            
            const selectedIds = this.getSelectedAttachmentIds();
            if (selectedIds.length === 0) {
                console.log('Please select images to process');
                return;
            }
            
            const conversionMap = {
                'pic_pilot_to_jpeg': 'jpeg',
                'pic_pilot_to_png': 'png', 
                'pic_pilot_to_webp': 'webp',
                'pic_pilot_compress': 'compress'
            };
            
            const conversionType = conversionMap[action];
            if (!conversionType) {
                return;
            }
            
            if (!confirm(`Process ${selectedIds.length} selected images?`)) {
                return;
            }
            
            this.processBulkAction(selectedIds, conversionType);
        },
        
        processBulkAction: function(attachmentIds, conversionType) {
            this.showBatchProgress(attachmentIds.length);
            
            // Process in batches to avoid timeouts
            const batchSize = 5;
            let processed = 0;
            
            const processBatch = (startIndex) => {
                const batch = attachmentIds.slice(startIndex, startIndex + batchSize);
                
                if (batch.length === 0) {
                    this.hideBatchProgress();
                    location.reload();
                    return;
                }
                
                $.ajax({
                    url: picPilotConverter.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pic_pilot_batch_convert',
                        attachment_ids: batch,
                        conversion_type: conversionType,
                        nonce: picPilotConverter.nonce
                    },
                    success: (response) => {
                        processed += batch.length;
                        this.updateBatchProgress(processed, attachmentIds.length);
                        
                        setTimeout(() => processBatch(startIndex + batchSize), 500);
                    },
                    error: () => {
                        console.log('Batch processing failed');
                        this.hideBatchProgress();
                    }
                });
            };
            
            processBatch(0);
        },
        
        getSelectedAttachmentIds: function() {
            const selected = [];
            $('input[name="media[]"]:checked').each(function() {
                selected.push(parseInt($(this).val()));
            });
            return selected;
        },
        
        showProgress: function(attachmentId, message) {
            const $progress = $(`#pic-pilot-progress-${attachmentId}`);
            $progress.html(`<div class="pic-pilot-progress-bar">
                <div class="pic-pilot-progress-text">${message}</div>
                <div class="pic-pilot-progress-spinner"></div>
            </div>`).show();
        },
        
        hideProgress: function(attachmentId) {
            $(`#pic-pilot-progress-${attachmentId}`).fadeOut();
        },
        
        showSuccess: function(attachmentId, data) {
            const message = data.message || 'Operation completed successfully!';
            let details = '';
            
            if (data.new_format) {
                details += `<br><small>New format: ${data.new_format}`;
                if (data.saved) {
                    details += ` | Saved: ${this.formatBytes(data.saved)}`;
                }
                details += '</small>';
            }
            
            const $progress = $(`#pic-pilot-progress-${attachmentId}`);
            $progress.html(`<div class="pic-pilot-success">
                ✅ ${message}${details}
            </div>`);
        },
        
        showError: function(attachmentId, message) {
            const $progress = $(`#pic-pilot-progress-${attachmentId}`);
            $progress.html(`<div class="pic-pilot-error">
                ❌ ${message || 'Operation failed'}
            </div>`);
        },
        
        showBatchProgress: function(total) {
            const $overlay = $('<div class="pic-pilot-batch-overlay">').appendTo('body');
            const $modal = $(`<div class="pic-pilot-batch-modal">
                <div class="pic-pilot-batch-header">
                    <h3>Processing Images</h3>
                </div>
                <div class="pic-pilot-batch-progress">
                    <div class="pic-pilot-batch-bar">
                        <div class="pic-pilot-batch-fill" style="width: 0%"></div>
                    </div>
                    <div class="pic-pilot-batch-text">0 / ${total} images processed</div>
                </div>
            </div>`).appendTo($overlay);
        },
        
        updateBatchProgress: function(processed, total) {
            const percentage = Math.round((processed / total) * 100);
            $('.pic-pilot-batch-fill').css('width', percentage + '%');
            $('.pic-pilot-batch-text').text(`${processed} / ${total} images processed`);
        },
        
        hideBatchProgress: function() {
            $('.pic-pilot-batch-overlay').fadeOut(function() {
                $(this).remove();
            });
        },
        
        showBatchResults: function(data) {
            alert(`Batch conversion completed!\nTotal: ${data.total}\nSuccessful: ${data.successful}\nFailed: ${data.total - data.successful}`);
        },
        
        refreshAttachmentData: function(attachmentId) {
            // Refresh the attachment row to show new format
            if ($('body').hasClass('upload-php')) {
                location.reload();
            }
        },
        
        formatBytes: function(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        MediaConverter.init();
    });
    
    // Global access for debugging
    window.PicPilotMediaConverter = MediaConverter;
    
})(jQuery);