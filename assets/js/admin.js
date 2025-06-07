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

    // Toggle display of TinyPNG section based on PNG engine select
    const pngEngineSelect = document.getElementById('png_engine');
    const tinypngSection = document.getElementById('pic-pilot-tinypng-section');
    function toggleTinypngSection() {
        if (pngEngineSelect && tinypngSection) {
            tinypngSection.style.display = (pngEngineSelect.value === 'tinypng') ? '' : 'none';
        }
    }
    if (pngEngineSelect) {
        pngEngineSelect.addEventListener('change', toggleTinypngSection);
        toggleTinypngSection(); // Set initial state
    }
});
