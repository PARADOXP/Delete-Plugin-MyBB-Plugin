<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

if(!defined('PLUGINLIBRARY'))
{
    define('PLUGINLIBRARY', MYBB_ROOT.'inc/plugins/pluginlibrary.php');
}

/**
 * Path of the file that marks the plugin as installed.
 *
 * @return string
 */
function delete_plugin_marker_path()
{
    return DELETE_PLUGIN_ROOT.'.installed';
}

function delete_plugin_install_run()
{
    delete_plugin_add_install_marker();
}

function delete_plugin_activate_run()
{
    delete_plugin_install_run();

    delete_plugin_admin_add_settings();
    delete_plugin_admin_add_stylesheet();
    delete_plugin_admin_add_templates();

    if(function_exists('rebuild_settings'))
    {
        rebuild_settings();
    }
}

function delete_plugin_deactivate_run()
{
    delete_plugin_admin_remove_stylesheet();
}

function delete_plugin_uninstall_run()
{
    delete_plugin_admin_remove_stylesheet();
    delete_plugin_admin_remove_templates();
    delete_plugin_admin_remove_settings();

    delete_plugin_remove_install_marker();
}

function delete_plugin_add_install_marker()
{
    $marker = delete_plugin_marker_path();

    if($marker !== '' && !file_exists($marker))
    {
        @file_put_contents($marker, '');
    }
}

function delete_plugin_remove_install_marker()
{
    $marker = delete_plugin_marker_path();

    if($marker !== '' && file_exists($marker))
    {
        @unlink($marker);
    }
}
