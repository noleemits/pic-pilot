<?php
// Pic Pilot Backup Manager Info Box

?>
<div class="pic-pilot-notices bar-left-blue" style="padding: 2rem 1rem 1rem; background-color: #fff; margin-bottom: 1rem;">
    <strong><?php esc_html_e('Backup Summary', 'pic-pilot'); ?></strong>
    <ul style="margin-top:8px;">
        <li>
            <?php
            printf(
                esc_html__('Total backups: %d', 'pic-pilot'),
                isset($backup_summary['count']) ? $backup_summary['count'] : 0
            );
            ?>
        </li>
        <li>
            <?php
            printf(
                esc_html__('Total backup disk usage: %s MB', 'pic-pilot'),
                isset($backup_summary['size_mb']) ? $backup_summary['size_mb'] : '0.00'
            );
            ?>
        </li>
        <li style="color: #888;">
            <?php esc_html_e('These stats update when you refresh this page.', 'pic-pilot'); ?>
        </li>
    </ul>
</div>