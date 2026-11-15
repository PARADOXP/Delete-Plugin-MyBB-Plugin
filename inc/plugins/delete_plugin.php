<?php
if(!defined('IN_MYBB'))
{
    die('This file cannot be accessed directly.');
}

if(!defined('DELETE_PLUGIN_ROOT'))
{
    define('DELETE_PLUGIN_ROOT', MYBB_ROOT.'inc/plugins/DeletePlugin/');
}

if(!defined('DELETE_PLUGIN_VERSION'))
{
    define('DELETE_PLUGIN_VERSION', '1.0.0');
}

if(!defined('PLUGINLIBRARY'))
{
    define('PLUGINLIBRARY', MYBB_ROOT.'inc/plugins/pluginlibrary.php');
}

require_once DELETE_PLUGIN_ROOT.'Functions/admin_functions.php';
require_once DELETE_PLUGIN_ROOT.'Functions/scan_functions.php';
require_once DELETE_PLUGIN_ROOT.'Functions/delete_functions.php';
require_once DELETE_PLUGIN_ROOT.'Functions/display_functions.php';
require_once DELETE_PLUGIN_ROOT.'install.php';

if(defined('IN_ADMINCP'))
{
    $plugins->add_hook('admin_config_plugins_begin', 'delete_plugin_admin_action');
    $plugins->add_hook('admin_config_plugins_plugin_list', 'delete_plugin_set_flag');
    $plugins->add_hook('admin_page_output_footer', 'delete_plugin_admin_footer');
    $plugins->add_hook('admin_config_permissions', 'delete_plugin_admin_permissions');
    $plugins->add_hook('admin_config_menu', 'delete_plugin_admin_menu');
    $plugins->add_hook('admin_config_action_handler', 'delete_plugin_admin_action_handler');
    $plugins->add_hook('admin_load', 'delete_plugin_admin_load');
}

function delete_plugin_info()
{
    global $lang;

    $lang->load('delete_plugin');

    return array(
        'name'          => 'Delete Plugin',
        'description'   => $lang->delete_plugin_desc,
        'website'       => 'https://mybb.epizy.com',
        'author'        => 'PARAD0X',
        'authorsite'    => 'https://community.mybb.com/user-134311.html',
        'version'       => DELETE_PLUGIN_VERSION,
        'guid'          => '',
        'compatibility' => '18*',
        'codename'      => 'delete_plugin'
    );
}

function delete_plugin_install()
{
    delete_plugin_install_run();
}

function delete_plugin_is_installed()
{
    return file_exists(delete_plugin_marker_path());
}

function delete_plugin_uninstall()
{
    delete_plugin_uninstall_run();
}

function delete_plugin_activate()
{
    delete_plugin_activate_run();
}

function delete_plugin_deactivate()
{
    delete_plugin_deactivate_run();
}

/**
 * Handles the "delete these files" request sent from the scan page.
 *
 * The file list is never trusted. The scan runs again and the posted paths are
 * intersected with it, so a forged request can only remove files the scanner
 * already accepted for this plugin.
 */
function delete_plugin_admin_action()
{
    global $mybb, $lang;

    $action = $mybb->get_input('action');

    if($action != 'do_delete')
    {
        return;
    }

    $lang->load('delete_plugin');

    if(!delete_plugin_admin_can('delete'))
    {
        delete_plugin_deny();
    }

    if(!verify_post_check($mybb->get_input('my_post_key')))
    {
        flash_message($lang->invalid_post_verify_key2, 'error');
        admin_redirect('index.php?module=config-plugins');
    }

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

    if(!is_file(MYBB_ROOT.'inc/plugins/'.$codename.'.php'))
    {
        flash_message($lang->delete_plugin_error_invalid, 'error');
        admin_redirect('index.php?module=config-plugins');
    }

    if($mybb->get_input('confirm') != 1)
    {
        flash_message($lang->delete_plugin_error_confirm, 'error');
        admin_redirect('index.php?module=config-delete_plugin&action=preview&plugin='.urlencode($codename));
    }

    // Large plugins can hold thousands of files, the scan must not time out.
    if(function_exists('my_set_time_limit'))
    {
        my_set_time_limit();
    }

    $scan = delete_plugin_scan($codename);

    if(empty($scan['files']))
    {
        $lang->delete_plugin_error_nofiles = $lang->sprintf($lang->delete_plugin_error_nofiles, $scan['plugin_name']);
        flash_message($lang->delete_plugin_error_nofiles, 'error');
        admin_redirect('index.php?module=config-plugins');
    }

    // Only the ticked files are removed, and only when the scan knows them.
    $requested = $mybb->get_input('delete', MyBB::INPUT_ARRAY);

    if(!is_array($requested))
    {
        $requested = array();
    }

    $picked = array();

    foreach($requested as $raw)
    {
        if(is_string($raw))
        {
            $picked[delete_plugin_real_path($raw)] = true;
        }
    }

    $files = array();

    foreach($scan['files'] as $rel_path)
    {
        if(isset($picked[$rel_path]))
        {
            $files[] = $rel_path;
        }
    }

    if(empty($files))
    {
        flash_message($lang->delete_plugin_error_selection, 'error');
        admin_redirect('index.php?module=config-delete_plugin&action=preview&plugin='.urlencode($codename));
    }

    $options = array(
        'uninstall'   => $mybb->get_input('run_uninstall') == 1,
        'remove_data' => $mybb->get_input('remove_data') == 1,
        'prune'       => $mybb->get_input('prune_folders') == 1,
    );

    $result = delete_plugin_perform($codename, $files, $scan, $options);

    $lang->delete_plugin_success = $lang->sprintf(
        $lang->delete_plugin_success,
        htmlspecialchars_uni($scan['plugin_name']),
        $result['deleted'],
        delete_plugin_format_size($result['bytes']),
        $result['folders'],
        $result['templates'],
        $result['settings'],
        count($result['errors'])
    );

    flash_message($lang->delete_plugin_success, $result['errors'] ? 'error' : 'success');
    admin_redirect('index.php?module=config-plugins');
}
