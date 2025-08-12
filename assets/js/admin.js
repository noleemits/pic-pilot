document.addEventListener('DOMContentLoaded', function () {
    // Toggle show/hide for the TinyPNG API key field
    const tinypngField = document.getElementById('tinypng_api_key');
    if (tinypngField) {
        // Only add the button if it doesn't already exist
        if (!document.getElementById('pic-pilot-toggle-api-key')) {
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            toggleBtn.id = 'pic-pilot-toggle-api-key';
            toggleBtn.className = 'button';
            toggleBtn.setAttribute('aria-pressed', 'false');
            toggleBtn.style.marginLeft = '8px';
            toggleBtn.textContent = 'Show';
            tinypngField.parentNode.insertBefore(toggleBtn, tinypngField.nextSibling);

            toggleBtn.addEventListener('click', function () {
                const isShown = tinypngField.type === 'text';
                tinypngField.type = isShown ? 'password' : 'text';
                toggleBtn.textContent = isShown ? 'Show' : 'Hide';
                toggleBtn.setAttribute('aria-pressed', String(!isShown));
            });
        }
    }

    // Function to toggle TinyPNG section visibility
    function toggleTinyPNGSection() {
        const pngEngineSelect = document.getElementById('png_engine');
        const tinyPNGSection = document.querySelector('.pic-pilot-tinypng-section');

        if (pngEngineSelect && tinyPNGSection) {
            if (pngEngineSelect.value === 'tinypng') {
                tinyPNGSection.style.display = 'block';
            } else {
                tinyPNGSection.style.display = 'none';
            }
        }
    }

    // Initial toggle on page load
    toggleTinyPNGSection();

    // Toggle when dropdown changes
    const pngEngineSelect = document.getElementById('png_engine');
    if (pngEngineSelect) {
        pngEngineSelect.addEventListener('change', toggleTinyPNGSection);
    }

});
//Resize on upload logic
document.addEventListener('DOMContentLoaded', function () {
    // Check if the elements exist before proceeding
    const checkbox = document.getElementById("resize_on_upload");
    const conditionalElements = document.querySelectorAll(".pp-resize-conditional");

    // If checkbox doesn't exist, exit the function
    if (!checkbox || conditionalElements.length === 0) return;

    function toggleResizeFields() {
        const isChecked = checkbox.checked;
        conditionalElements.forEach(element => {
            // Add a smooth transition effect
            element.style.transition = 'opacity 0.3s ease, max-height 0.3s ease';
            
            if (isChecked) {
                element.style.display = 'block';
                element.style.opacity = '1';
                element.style.maxHeight = '200px';
            } else {
                element.style.opacity = '0.5';
                element.style.maxHeight = '0';
                setTimeout(() => {
                    if (!checkbox.checked) {
                        element.style.display = 'none';
                    }
                }, 300);
            }
        });
    }

    // Initial check and event listener setup
    toggleResizeFields();
    checkbox.addEventListener("change", toggleResizeFields);
});

//Bulk optimize logic
document.addEventListener('DOMContentLoaded', function () {
    const startBtn = document.getElementById('pp-start-bulk');
    const pauseBtn = document.getElementById('pp-pause-bulk');
    const resumeBtn = document.getElementById('pp-resume-bulk');
    const stopBtn = document.getElementById('pp-stop-bulk');
    const progressEl = document.getElementById('pp-bulk-progress');
    
    // Exit early if bulk optimization elements don't exist (not on bulk optimize page)
    if (!startBtn) {
        console.log('PicPilot: Bulk optimization elements not found, skipping bulk optimization setup');
        return;
    }
    
    console.log('PicPilot: Bulk optimization elements found, initializing');

    let optimizedImages = [];
    let currentPage = 1;
    const itemsPerPage = 20;

    //State buttons
    function setButtonStates(state) {
        const states = {
            idle: { start: true, pause: false, resume: false, stop: false },
            scanning: { start: false, pause: false, resume: false, stop: false },
            running: { start: false, pause: true, resume: false, stop: true },
            paused: { start: false, pause: false, resume: true, stop: true },
            stopped: { start: true, pause: false, resume: false, stop: false },
            done: { start: true, pause: false, resume: false, stop: false },
        };

        const map = states[state] || states.idle;

        startBtn.disabled = !map.start;
        pauseBtn.disabled = !map.pause;
        resumeBtn.disabled = !map.resume;
        stopBtn.disabled = !map.stop;
    }


    let paused = false;
    let stopped = false;
    const delay = 2000; // 2s delay between batches

    const ajax = (action, data = {}) => {
        return fetch(pic_pilot_admin.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: action,
                _ajax_nonce: pic_pilot_admin.nonce,
                ...data
            })
        }).then(res => {
            if (!res.ok) throw new Error(`Request failed: ${res.status}`);
            return res.json();
        });
    };

    const processBatch = () => {
        if (paused || stopped) return;

        ajax('pic_pilot_process_batch')
            .then(res => {
                if (res.success) {
                    const data = res.data;
                    // ✅ Collect image results for frontend log
                    if (Array.isArray(data.results)) {
                        optimizedImages.push(...data.results);
                        renderLogTable(currentPage); // draw new rows
                    }

                    if (data.paused) {
                        progressEl.textContent = 'Paused...';
                        return;
                    }

                    if (data.done) {
                        progressEl.textContent = 'Completed!';
                        setButtonStates('done');
                        return;
                    }

                    const { processed, total, percent } = data.progress;
                    progressEl.textContent = `Processed ${processed} / ${total} (${percent}%)`;

                    setTimeout(processBatch, delay); // throttle next batch
                } else {
                    console.error('Batch error:', res);
                    progressEl.textContent = 'Error during processing';
                }
            })
            .catch(err => {
                console.error('Batch AJAX error:', err);
                progressEl.textContent = 'AJAX error during processing';
            });
    };

    //Make bytes human-readable
    function formatBytes(bytes) {
        if (!bytes || isNaN(bytes)) return '0 B';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }


    startBtn?.addEventListener('click', () => {
        paused = false;
        stopped = false;
        progressEl.textContent = 'Scanning images...';
        setButtonStates('scanning');

        ajax('pic_pilot_scan_bulk_optimize')

            .then(res => {
                if (!res.success || !res.data.ids || !res.data.ids.length) {
                    alert("No optimizable images found.");
                    progressEl.textContent = 'No images to optimize.';
                    return;
                }

                const imageIds = res.data.ids;

                ajax('pic_pilot_start_bulk', {
                    ids: imageIds.join(',')
                })
                    .then(startRes => {
                        if (startRes.success) {
                            progressEl.textContent = 'Starting optimization...';
                            setTimeout(processBatch, delay);
                            setButtonStates('running');
                        } else {
                            alert(startRes.data?.message || "Failed to start.");
                            progressEl.textContent = 'Start failed.';
                        }
                    })
                    .catch(err => {
                        console.error('Start AJAX error:', err);
                        progressEl.textContent = 'AJAX error starting optimization';
                    });
            })
            .catch(err => {
                console.error('Scan AJAX error:', err);
                progressEl.textContent = 'AJAX error scanning images';
            });
    });

    pauseBtn?.addEventListener('click', () => {
        paused = true;
        ajax('pic_pilot_pause_bulk');
        progressEl.textContent = 'Paused by user.';
        setButtonStates('paused');
    });

    resumeBtn?.addEventListener('click', () => {
        paused = false;
        progressEl.textContent = 'Resuming...';
        setButtonStates('running');

        ajax('pic_pilot_resume_bulk').then(() => {
            setTimeout(processBatch, delay);
        });
    });


    stopBtn?.addEventListener('click', () => {
        stopped = true;
        ajax('pic_pilot_stop_bulk');
        progressEl.textContent = 'Stopped.';
        setButtonStates('stopped');
    });
    //Set button states to idle
    setButtonStates('idle');

    function renderLogTable(page = 1) {
        const start = (page - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        const pageItems = optimizedImages.slice(start, end);

        const container = document.getElementById('pp-log-table');
        if (!container) return;

        container.innerHTML = pageItems.map(entry => `
    <div class="pp-log-row" style="border-bottom: 1px solid #e0e0e0; padding: 12px 0; font-size: 14px;">
        <div style="font-weight: 600; color: #222;">${entry.filename}</div>
        <div style="color: #666; font-size: 12px; margin-bottom: 6px;">${entry.path}</div>
        <div style="display: flex; flex-wrap: wrap; gap: 10px; font-size: 13px; line-height: 1.6;">
            <span><strong>Engine:</strong> ${entry.engine || 'Unknown'}</span>
            <span><strong>Saved:</strong> 
                <span style="color: #007cba;">${entry.saved_percent}%</span> 
                <small style="color: #999;">(main: ${entry.main_percent}%, thumbs: ${entry.thumb_percent}%)</small>
            </span>
            <span><strong>Thumbnails Optimized:</strong> ${entry.thumb_count ?? 0}</span>
        </div>
    </div>
`).join('');


        renderPaginationControls(page);
    }

    function renderPaginationControls(current) {
        const totalPages = Math.ceil(optimizedImages.length / itemsPerPage);
        const container = document.getElementById('pp-log-pagination');
        if (!container) return;

        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        container.innerHTML = `
        <button ${current === 1 ? 'disabled' : ''} id="pp-log-prev">◀ Prev</button>
        Page ${current} of ${totalPages}
        <button ${current === totalPages ? 'disabled' : ''} id="pp-log-next">Next ▶</button>
    `;

        document.getElementById('pp-log-prev')?.addEventListener('click', () => {
            currentPage--;
            renderLogTable(currentPage);
        });

        document.getElementById('pp-log-next')?.addEventListener('click', () => {
            currentPage++;
            renderLogTable(currentPage);
        });
    }

    document.getElementById('pp-scan-bulk')?.addEventListener('click', () => {
        const summaryEl = document.getElementById('pp-scan-summary');
        const startBtn = document.getElementById('pp-start-bulk');

        summaryEl.textContent = 'Scanning...';
        startBtn.disabled = true;

        ajax('pic_pilot_scan_bulk_optimize').then(res => {
            if (!res.success || !res.data.ids.length) {
                summaryEl.textContent = 'No optimizable images found.';
                return;
            }

            window.pic_pilot_bulk_ids = res.data.ids;

            summaryEl.innerHTML = `<strong>${res.data.count}</strong> images ready to be optimized.`;
            startBtn.disabled = false;
        }).catch(err => {
            summaryEl.textContent = 'Scan failed.';
            console.error('Scan error:', err);
        });
    });

});

// Optimization tooltip functionality
document.addEventListener('DOMContentLoaded', function () {
    // Handle tooltip click for optimization details (better for mobile/live sites)
    const tooltipElements = document.querySelectorAll('.pic-pilot-has-tooltip');
    let currentTooltip = null;
    
    // Close tooltip when clicking outside
    document.addEventListener('click', function(e) {
        if (currentTooltip && !e.target.closest('.pic-pilot-has-tooltip') && !e.target.closest('.pic-pilot-tooltip-popup')) {
            closeTooltip();
        }
    });
    
    function closeTooltip() {
        if (currentTooltip) {
            currentTooltip.style.opacity = '0';
            setTimeout(() => {
                if (currentTooltip && currentTooltip.parentNode) {
                    currentTooltip.parentNode.removeChild(currentTooltip);
                }
                currentTooltip = null;
            }, 200);
        }
    }
    
    tooltipElements.forEach(element => {
        const tooltipContent = element.querySelector('.pic-pilot-tooltip-content');
        if (!tooltipContent) return;
        
        // Show tooltip on click
        element.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // Close existing tooltip
            if (currentTooltip) {
                closeTooltip();
                return;
            }
            
            currentTooltip = document.createElement('div');
            currentTooltip.className = 'pic-pilot-tooltip-popup';
            currentTooltip.innerHTML = tooltipContent.innerHTML;
            document.body.appendChild(currentTooltip);
            
            // Position tooltip
            const rect = element.getBoundingClientRect();
            currentTooltip.style.position = 'fixed';
            currentTooltip.style.visibility = 'hidden'; // Hide while measuring
            currentTooltip.style.opacity = '1'; // Make visible for measurement
            
            // Smart positioning to avoid going off-screen
            let left = rect.left + 10;
            let top = rect.bottom + 5;
            
            // Get actual tooltip dimensions after content is set
            const tooltipRect = currentTooltip.getBoundingClientRect();
            const tooltipWidth = tooltipRect.width || 320; // fallback to max-width
            const tooltipHeight = tooltipRect.height || 200; // fallback estimate
            
            // Check if tooltip would go off the right edge
            if (left + tooltipWidth > window.innerWidth - 10) {
                left = rect.right - tooltipWidth;
                // If still off-screen, position from right edge
                if (left < 10) {
                    left = window.innerWidth - tooltipWidth - 10;
                }
            }
            
            // Check if tooltip would go off the bottom edge  
            if (top + tooltipHeight > window.innerHeight - 10) {
                top = rect.top - tooltipHeight - 5;
                // If still off-screen, position from top
                if (top < 10) {
                    top = 10;
                }
            }
            
            // Ensure tooltip stays within viewport bounds
            currentTooltip.style.left = Math.max(10, Math.min(left, window.innerWidth - tooltipWidth - 10)) + 'px';
            currentTooltip.style.top = Math.max(10, Math.min(top, window.innerHeight - tooltipHeight - 10)) + 'px';
            currentTooltip.style.zIndex = '10000';
            currentTooltip.style.cursor = 'default';
            
            // Add close button
            const closeBtn = document.createElement('span');
            closeBtn.innerHTML = '×';
            closeBtn.style.cssText = 'position: absolute; top: 5px; right: 8px; cursor: pointer; font-size: 18px; color: #999; font-weight: bold;';
            closeBtn.addEventListener('click', closeTooltip);
            currentTooltip.appendChild(closeBtn);
            
            // Show with fade-in after positioning
            currentTooltip.style.opacity = '0';
            currentTooltip.style.visibility = 'visible';
            setTimeout(() => {
                if (currentTooltip) currentTooltip.style.opacity = '1';
            }, 10);
        });
    });
});

// Accordion functionality for settings sections
function initializeAccordion() {
    const settingsContainer = document.querySelector('.pic-pilot-settings');
    if (!settingsContainer) return;
    
    // Find all section headings (h2 elements) within the form
    const sectionHeadings = settingsContainer.querySelectorAll('form h2');
    
    // Wrap each section's content in a container div
    sectionHeadings.forEach((heading, index) => {
        const contentWrapper = document.createElement('div');
        contentWrapper.className = 'pic-pilot-section-content';
        
        // Find all elements that belong to this section (until next h2 or end)
        let currentElement = heading.nextElementSibling;
        const sectionElements = [];
        
        while (currentElement && currentElement.tagName !== 'H2' && !currentElement.classList.contains('submit')) {
            sectionElements.push(currentElement);
            currentElement = currentElement.nextElementSibling;
        }
        
        // Move all section elements into the wrapper
        sectionElements.forEach(element => {
            contentWrapper.appendChild(element);
        });
        
        // Insert the wrapper after the heading
        heading.parentNode.insertBefore(contentWrapper, heading.nextSibling);
        
        // Make all sections collapsed by default except the first one
        if (index > 0) {
            heading.classList.add('collapsed');
            contentWrapper.classList.add('collapsed');
        }
        
        // Add click event listener
        heading.addEventListener('click', function() {
            const isCollapsed = this.classList.contains('collapsed');
            const content = this.nextElementSibling;
            
            if (isCollapsed) {
                // Expand this section
                this.classList.remove('collapsed');
                content.classList.remove('collapsed');
            } else {
                // Collapse this section
                this.classList.add('collapsed');
                content.classList.add('collapsed');
            }
        });
    });
}

// Initialize accordion when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    // Initialize accordion functionality for settings sections
    if (document.querySelector('.pic-pilot-settings')) {
        initializeAccordion();
        initializeSettingsDependencies();
    }
});

// Settings dependencies management
function initializeSettingsDependencies() {
    const uploadModeRadios = document.querySelectorAll('input[name="pic_pilot_options[upload_mode]"]');
    
    if (uploadModeRadios.length === 0) return;
    
    // Define dependency rules
    const dependencyRules = {
        'upload_mode': {
            'compress': {
                enable: ['compression_engine', 'jpeg_quality', 'resize_during_compression', 'compression_max_width', 'keep_original_after_compression_resize', 'convert_png_to_jpeg_in_compress_mode'],
                disable: ['convert_to_webp_on_upload', 'webp_engine', 'webp_quality', 'convert_png_to_jpeg_if_opaque', 'png_to_jpeg_fallback', 'resize_during_conversion', 'conversion_max_width', 'keep_original_after_conversion_resize']
            },
            'convert': {
                enable: ['convert_to_webp_on_upload', 'webp_engine', 'webp_quality', 'convert_png_to_jpeg_if_opaque', 'png_to_jpeg_fallback', 'resize_during_conversion', 'conversion_max_width', 'keep_original_after_conversion_resize'],
                disable: ['compression_engine', 'jpeg_quality', 'resize_during_compression', 'compression_max_width', 'keep_original_after_compression_resize', 'convert_png_to_jpeg_in_compress_mode']
            },
            'disabled': {
                enable: [],
                disable: ['compression_engine', 'jpeg_quality', 'resize_during_compression', 'compression_max_width', 'keep_original_after_compression_resize', 'convert_png_to_jpeg_in_compress_mode', 'convert_to_webp_on_upload', 'webp_engine', 'webp_quality', 'convert_png_to_jpeg_if_opaque', 'png_to_jpeg_fallback', 'resize_during_conversion', 'conversion_max_width', 'keep_original_after_conversion_resize']
            }
        }
    };
    
    function updateSettingsDependencies() {
        const selectedMode = document.querySelector('input[name="pic_pilot_options[upload_mode]"]:checked')?.value;
        
        if (!selectedMode || !dependencyRules.upload_mode[selectedMode]) return;
        
        const rules = dependencyRules.upload_mode[selectedMode];
        
        // Enable settings
        rules.enable.forEach(settingName => {
            enableSetting(settingName);
        });
        
        // Disable settings
        rules.disable.forEach(settingName => {
            disableSetting(settingName);
        });
        
        // Update section headings based on mode
        updateSectionHeadings(selectedMode);
    }
    
    function enableSetting(settingName) {
        const elements = getSettingElements(settingName);
        elements.forEach(element => {
            element.disabled = false;
            element.style.opacity = '1';
            element.style.pointerEvents = 'auto';
            
            // Remove disabled message if it exists
            const disabledMsg = element.closest('tr, .form-field, div')?.querySelector('.pic-pilot-disabled-message');
            if (disabledMsg) {
                disabledMsg.remove();
            }
        });
    }
    
    function disableSetting(settingName) {
        const elements = getSettingElements(settingName);
        elements.forEach(element => {
            element.disabled = true;
            element.style.opacity = '0.5';
            element.style.pointerEvents = 'none';
            
            // Add disabled message if it doesn't exist
            const container = element.closest('tr, .form-field, div');
            if (container && !container.querySelector('.pic-pilot-disabled-message')) {
                const message = document.createElement('div');
                message.className = 'pic-pilot-disabled-message';
                message.style.cssText = 'font-size: 12px; color: #999; font-style: italic; margin-top: 4px;';
                message.textContent = 'Available when upload processing mode is configured';
                container.appendChild(message);
            }
        });
    }
    
    function getSettingElements(settingName) {
        // Look for various input types (checkbox, radio, select, text)
        const selectors = [
            `input[name="pic_pilot_options[${settingName}]"]`,
            `input[name="pic_pilot_options[${settingName}][]"]`,
            `select[name="pic_pilot_options[${settingName}]"]`,
            `#${settingName}`
        ];
        
        let elements = [];
        selectors.forEach(selector => {
            elements.push(...document.querySelectorAll(selector));
        });
        
        return elements;
    }
    
    function updateSectionHeadings(selectedMode) {
        const compressionSection = document.querySelector('input[name="pic_pilot_options[compression_engine]"]')?.closest('.form-table')?.previousElementSibling;
        const conversionSection = document.querySelector('input[name="pic_pilot_options[convert_to_webp_on_upload]"]')?.closest('.form-table')?.previousElementSibling;
        
        if (compressionSection && compressionSection.tagName === 'H2') {
            if (selectedMode === 'compress') {
                compressionSection.style.opacity = '1';
                compressionSection.innerHTML = '🗜️ Compression Mode Settings <span style="color: #007cba;">(Active)</span>';
            } else {
                compressionSection.style.opacity = '0.6';
                compressionSection.innerHTML = '🗜️ Compression Mode Settings <span style="color: #999;">(Disabled)</span>';
            }
        }
        
        if (conversionSection && conversionSection.tagName === 'H2') {
            if (selectedMode === 'convert') {
                conversionSection.style.opacity = '1';  
                conversionSection.innerHTML = '🔄 Conversion Mode Settings <span style="color: #007cba;">(Active)</span>';
            } else {
                conversionSection.style.opacity = '0.6';
                conversionSection.innerHTML = '🔄 Conversion Mode Settings <span style="color: #999;">(Disabled)</span>';
            }
        }
    }
    
    // Initialize on page load
    updateSettingsDependencies();
    
    // Add event listeners to upload mode radios
    uploadModeRadios.forEach(radio => {
        radio.addEventListener('change', updateSettingsDependencies);
        radio.addEventListener('change', updateRadioButtonStyling);
        radio.addEventListener('change', validateConversionMode);
    });
    
    // Add change listeners to conversion options
    const conversionOptions = ['convert_to_webp_on_upload', 'convert_png_to_jpeg_if_opaque'];
    conversionOptions.forEach(optionName => {
        const element = document.querySelector(`input[name="pic_pilot_options[${optionName}]"]`);
        if (element) {
            element.addEventListener('change', validateConversionMode);
        }
    });
    
    // Initialize radio button styling
    updateRadioButtonStyling();
    
    // Initial conversion mode validation
    validateConversionMode();
}

// Validate conversion mode settings
function validateConversionMode() {
    const selectedMode = document.querySelector('input[name="pic_pilot_options[upload_mode]"]:checked')?.value;
    
    if (selectedMode !== 'convert') return;
    
    const webpEnabled = document.querySelector('input[name="pic_pilot_options[convert_to_webp_on_upload]"]')?.checked;
    const pngToJpegEnabled = document.querySelector('input[name="pic_pilot_options[convert_png_to_jpeg_if_opaque]"]')?.checked;
    
    // Remove existing warning
    const existingWarning = document.querySelector('.pic-pilot-conversion-warning');
    if (existingWarning) {
        existingWarning.remove();
    }
    
    // Add warning if no conversion options are enabled
    if (!webpEnabled && !pngToJpegEnabled) {
        const conversionSection = document.querySelector('input[name="pic_pilot_options[convert_to_webp_on_upload]"]')?.closest('.form-table')?.previousElementSibling;
        if (conversionSection && conversionSection.tagName === 'H2') {
            const warning = document.createElement('div');
            warning.className = 'notice notice-warning inline pic-pilot-conversion-warning';
            warning.style.margin = '10px 0';
            warning.innerHTML = '<p><strong>⚠️ No conversion options selected!</strong> Convert mode is enabled but no format conversions are configured. Enable WebP conversion or PNG→JPEG conversion, or switch to Compress mode instead.</p>';
            conversionSection.parentNode.insertBefore(warning, conversionSection.nextSibling);
        }
    }
}

// Handle radio button styling for browsers that don't support :has()
function updateRadioButtonStyling() {
    const radioOptions = document.querySelectorAll('.pic-pilot-radio-option');
    
    radioOptions.forEach(label => {
        const radio = label.querySelector('input[type="radio"]');
        if (radio) {
            if (radio.checked) {
                label.style.borderColor = '#007cba';
                label.style.background = '#e7f3ff';
                label.style.color = '#007cba';
                label.style.fontWeight = '600';
            } else {
                label.style.borderColor = '#ddd';
                label.style.background = '#fff';
                label.style.color = '';
                label.style.fontWeight = '';
            }
        }
    });
}

