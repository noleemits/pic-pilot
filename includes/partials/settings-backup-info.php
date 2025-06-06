<?php
// Pic Pilot Backup Manager Info Box
$uploads = wp_upload_dir();
$backup_root = trailingslashit($uploads['basedir']) . 'pic-pilot-backups/';
$backup_dirs = is_dir($backup_root) ? array_filter(scandir($backup_root), function ($d) use ($backup_root) {
    return $d !== '.' && $d !== '..' && is_dir($backup_root . $d);
}) : [];

$backup_count = count($backup_dirs);
$total_size = 0;
foreach ($backup_dirs as $dir) {
    $dir_path = $backup_root . $dir . '/';
    foreach (glob($dir_path . '*') as $file) {
        $total_size += filesize($file);
    }
}
$total_size_mb = $total_size ? round($total_size / 1024 / 1024, 2) : 0;

$backup_summary = [
    'count'   => $backup_count,
    'size_mb' => $total_size_mb,
];

?>
<div class="pic-pilot-notices bar-left-blue">
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
    <strong><?php esc_html_e('How Pic Pilot Backups Work', 'pic-pilot'); ?></strong>
    <ul>
        <li><?php esc_html_e('Backups include only the scaled image file and all WordPress-generated thumbnails. The original upload file is only included if it is still used by WordPress.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Backups can double your disk space usage for each optimized image. Regularly check your available storage.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Restoring, optimizing, and re-optimizing may result in slightly different file sizes due to lossy compression. This is normal.', 'pic-pilot'); ?></li>
        <li><?php esc_html_e('Use the Bulk Delete feature to clean up unused backups and free up disk space.', 'pic-pilot'); ?></li>
    </ul>
</div>