<?php
/**
 * Spustí se při smazání pluginu z administrace.
 * Uklidí po sobě uložené volby pluginu.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('od_amh_hidden');
delete_option('od_amh_bar_items');
delete_option('od_amh_bar_front');
