#!/usr/local/bin/php
<?php
/*
 * wg_auto_update.php
 *
 * AmneziaWireguard Auto Update Script
 * This script is executed by cron to automatically check and install package updates.
 */

require_once('config.inc');
require_once('util.inc');

const LOG_FILE = '/var/log/amneziawg_auto_update.log';
const LOCK_FILE = '/var/run/amneziawg_auto_update.lock';
const PKG_CMD = '/usr/sbin/pkg';
const PKG_NAME = 'pfSense-pkg-Amneziawireguard';

function log_update($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[{$timestamp}] {$message}\n";
    file_put_contents(LOG_FILE, $log_message, FILE_APPEND);
}

/* Simple lock to avoid overlapping runs */
if (file_exists(LOCK_FILE)) {
    $age = time() - filemtime(LOCK_FILE);
    // If lock is newer than 15 minutes, assume previous run is still active or stuck.
    if ($age < 900) {
        // Another instance is probably running.
        log_update('Previous run still active, exiting.');
        exit(0);
    } else {
        // Stale lock, remove it.
        @unlink(LOCK_FILE);
    }
}

touch(LOCK_FILE);

log_update('Starting AmneziaWireguard auto update check');

/* Step 1: Update package repository */
log_update('Updating package repository');
$output = [];
$ret_code = 0;

exec('env IGNORE_OSVERSION=yes ' . PKG_CMD . ' update -f 2>&1', $output, $ret_code);

if ($ret_code !== 0) {
    log_update('Failed to update package repository: ' . implode("\n", $output));
    @unlink(LOCK_FILE);
    exit(1);
}
log_update('Package repository updated successfully');

/* Step 2: Install or upgrade AWG package */
log_update('Installing or upgrading ' . PKG_NAME);
$output = [];
$ret_code = 0;

exec('env IGNORE_OSVERSION=yes ' . PKG_CMD . ' install -y ' . PKG_NAME . ' 2>&1', $output, $ret_code);

if ($ret_code === 0) {
    log_update('Package install or upgrade completed successfully');
    log_update('Output: ' . implode("\n", $output));
} else {
    log_update('Package install or upgrade failed');
    log_update('Error output: ' . implode("\n", $output));
    @unlink(LOCK_FILE);
    exit(1);
}

log_update('Auto update check completed');

@unlink(LOCK_FILE);
exit(0);
?>
