<?php
// Docs file for Pic Pilot – loaded by SettingsPage::render_docs_tab()
?>
<div class="pic-pilot-docs-content">

    <h2><?php esc_html_e('Pic Pilot Documentation', 'pic-pilot'); ?></h2>

    <h3><?php esc_html_e('Why External Compressors for PNG?', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('PNG images are lossless by design and notoriously hard to compress efficiently with only server tools. By integrating with high-quality APIs like TinyPNG, Pic Pilot delivers industry-best results for PNGs—often shrinking files by 50% or more with no visible quality loss. This protects your bandwidth and SEO, and lets you optimize even large or transparent PNGs that would otherwise clog your site.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('How Local Compression Impacts Your Server', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('JPEG compression (and in the future, WebP conversion) can be handled locally, but these processes use real CPU and memory. On shared or limited servers, this can temporarily slow down other site tasks. Pic Pilot detects your server\'s available image libraries (GD, Imagick, WebP) in the General tab to ensure everything runs smoothly and to help you troubleshoot issues if you see "Missing" warnings.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('Smart Engine Routing: Always the Best Compressor', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('Pic Pilot intelligently chooses the best available compressor for each image type. JPEGs use your server\'s local engine for speed and privacy. PNGs are routed to external APIs for top-tier results. In the future, you\'ll be able to add or switch engines for even more flexibility.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('Server Settings Detection', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('On the General tab, Pic Pilot scans your server for Imagick, GD, and WebP support. This lets you know exactly what optimizations and conversions are possible on your hosting plan. If you see warnings, ask your host for the missing extension or use an external compressor.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('Why We Don’t Back Up the Main Original File', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('WordPress often creates a scaled version of uploaded images to prevent oversized files from affecting your site. Only this main scaled image (plus thumbnails) is actually used in posts, products, and galleries. To save disk space and avoid confusion, Pic Pilot backs up only what WordPress uses by default. Advanced users can enable additional backup features in future updates.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('Why Thumbnails Are Backed Up Too', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('Most page builders, themes, and galleries rely on WordPress-generated thumbnails. If you restore or re-optimize an image but don’t back up the thumbnails, your front-end images may break or look blurry. Pic Pilot\'s full backup system guarantees a complete, safe restore every time.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('What Sets Pic Pilot Apart', 'pic-pilot'); ?></h3>
    <ul>
        <li><?php esc_html_e('Full control—no forced external storage or subscriptions.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Smart routing per image type for the best compression results.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Backups and restore are always in sync with the current image state.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Backups double disk usage, but ensure you can safely revert without breaking the Media Library.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Disk cleanup and bulk actions make managing even huge sites safe.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Pro-level docs and warnings—no hidden limitations.', 'pic-pilot'); ?></li>
    </ul>

    <h3><?php esc_html_e('How Pic Pilot Helps You Save Space', 'pic-pilot'); ?></h3>
    <p><?php esc_html_e('Unlike bloated plugins that hoard multiple backups or never remove old files, Pic Pilot lets you delete unused originals and thumbnails at any time, and bulk clean up orphaned backups. With advanced tools coming soon, you can choose the level of redundancy and space-saving that fits your workflow.', 'pic-pilot'); ?></p>

    <h3><?php esc_html_e('Other Useful Information', 'pic-pilot'); ?></h3>
    <ul>
        <li><?php esc_html_e('Restoring, re-optimizing, and repeating these actions can result in slightly different file sizes due to lossy compression—this is expected.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Backups include only files actively used by WordPress (main scaled image and all active thumbnails).', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Bulk actions, advanced tools, and new engine support are in development—see the Tools and Advanced tabs.', 'pic-pilot'); ?></li>
    </ul>

</div>