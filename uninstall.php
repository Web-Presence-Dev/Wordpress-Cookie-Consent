<?php
/**
 * Removes the plugin's settings and caches when it's deleted from Plugins.
 * Visitors' wpcc_consent cookies expire on their own.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('wpcc_settings');
delete_option('wpcc_scan_result');
delete_option('wpcc_scan_ignored');
delete_site_transient('wpcc_github_release');
