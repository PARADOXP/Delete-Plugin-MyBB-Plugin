<?php
/**
 * Delete Plugin scan page.
 *
 * Reached from the Delete link on the plugin list. Shows what the advanced
 * scan found and what it will leave alone before a single file is removed.
 */

if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

global $mybb, $lang, $page;

$lang->load('delete_plugin');

$codename = delete_plugin_normalize_codename($mybb->get_input('plugin'));

if($codename === '')
{
    flash_message($lang->delete_plugin_error_noplugin, 'error');
    admin_redirect('index.php?module=config-plugins');
}

if($codename === 'delete_plugin')
{
    flash_message($lang->delete_plugin_error_self, 'error');
    admin_redirect('index.php?module=config-plugins');
}

if(!delete_plugin_admin_can('delete'))
{
    delete_plugin_deny();
}

if(!file_exists(MYBB_ROOT.'inc/plugins/'.$codename.'.php'))
{
    flash_message($lang->delete_plugin_error_invalid, 'error');
    admin_redirect('index.php?module=config-plugins');
}

// Large plugins can hold thousands of files, the scan must not time out.
if(function_exists('my_set_time_limit'))
{
    my_set_time_limit();
}

$delete_plugin_scan = delete_plugin_scan($codename);

if(empty($delete_plugin_scan['files']))
{
    $lang->delete_plugin_error_nofiles = $lang->sprintf($lang->delete_plugin_error_nofiles, $delete_plugin_scan['plugin_name']);
    flash_message($lang->delete_plugin_error_nofiles, 'error');
    admin_redirect('index.php?module=config-plugins');
}

delete_plugin_output_preview($delete_plugin_scan, $codename);
