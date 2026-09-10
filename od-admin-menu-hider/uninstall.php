<?php
/**
 * Spustí se při smazání pluginu z administrace.
 * Uklidí po sobě uloženou volbu skrytých položek.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('od_amh_hidden');
