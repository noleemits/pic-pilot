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

