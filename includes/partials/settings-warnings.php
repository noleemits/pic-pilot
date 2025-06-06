<?php
// Pic Pilot General/Advanced Tab Warnings
?>

<div class="pic-pilot-notices bar-left-orange">
    <div style="margin-bottom: 18px; max-width: 700px;">
        <strong><?php esc_html_e('Important:', 'pic-pilot'); ?></strong>
        <?php esc_html_e('Enabling backups will increase disk usage—Pic Pilot creates a backup for every optimized image (main scaled file + all thumbnails), which can double your storage needs for those images. Always monitor your available space, especially on shared or limited hosting.', 'pic-pilot'); ?>
    </div>

    <div style="margin-bottom: 18px; max-width: 700px;">
        <?php esc_html_e('If you enable "Delete unused originals", you will lose the ability to restore to the original upload (only the current WordPress-used files are preserved in backups).', 'pic-pilot'); ?>
    </div>
</div>