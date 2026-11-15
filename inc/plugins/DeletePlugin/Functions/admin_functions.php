<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Adds the Delete Plugin page to the Configuration tab.
 *
 * @param array $sub_menu Configuration sub menu
 * @return void
 */
function delete_plugin_admin_menu(&$sub_menu)
{
    global $lang;

    $lang->load('delete_plugin');

    $sub_menu[] = array(
        'id'    => 'delete_plugin',
        'title' => $lang->delete_plugin_title,
        'link'  => 'index.php?module=config-delete_plugin',
    );
}

/**
 * Routes the module to the plugin owned admin page.
 *
 * @param array $actions Config module actions
 * @return void
 */
function delete_plugin_admin_action_handler(&$actions)
{
    $actions['delete_plugin'] = array(
        'active' => 'delete_plugin',
        'file'   => 'delete_plugin.php',
    );
}

/**
 * Loads the plugin admin page and stops MyBB from loading its own stub.
 *
 * @return void
 */
function delete_plugin_admin_load()
{
    global $page, $lang;

    if(!isset($page->active_action) || $page->active_action != 'delete_plugin')
    {
        return;
    }

    if(!delete_plugin_admin_can('delete'))
    {
        delete_plugin_deny();
    }

    $lang->load('delete_plugin');

    $admin_file = DELETE_PLUGIN_ROOT.'Admin/preview.php';

    if(!file_exists($admin_file))
    {
        return;
    }

    require $admin_file;
    exit;
}

/**
 * Hook: admin_config_permissions
 *
 * Adds the Delete Plugin permissions to the Configuration & Plugins permission
 * screens, per user or per usergroup.
 *
 * @param array $admin_permissions Current config module permissions
 * @return array
 */
function delete_plugin_admin_permissions($admin_permissions)
{
    global $lang;

    $lang->load('delete_plugin');

    $admin_permissions['delete']          = $lang->can_delete_plugins;
    $admin_permissions['delete_link']     = $lang->can_see_delete_link;
    $admin_permissions['delete_plugin']   = $lang->can_manage_delete_plugin;

    return $admin_permissions;
}

/**
 * Checks one Delete Plugin permission. Super admins always pass.
 *
 * @param string $key One of: delete, delete_link, delete_plugin
 * @return bool
 */
function delete_plugin_admin_can($key)
{
    global $mybb;

    if(empty($mybb->user['uid']))
    {
        return false;
    }

    if(is_super_admin($mybb->user['uid']))
    {
        return true;
    }

    if(!empty($mybb->admin['permissions']) && is_array($mybb->admin['permissions']))
    {
        return isset($mybb->admin['permissions']['config'][$key]) && $mybb->admin['permissions']['config'][$key] == '1';
    }

    return false;
}

/**
 * Stops the current action with a "no permission" message.
 *
 * @return void
 */
function delete_plugin_deny()
{
    global $lang;

    $lang->load('delete_plugin');

    flash_message($lang->delete_plugin_error_denied, 'error');
    admin_redirect('index.php?module=config-plugins');
}

/**
 * Hook: admin_config_plugins_plugin_list
 *
 * Remembers that the plugin list is being rendered so the footer hook knows it
 * has work to do.
 *
 * @return void
 */
function delete_plugin_set_flag()
{
    global $delete_plugin_on_plugins_page;

    $delete_plugin_on_plugins_page = true;
}

/**
 * Hook: admin_page_output_footer
 *
 * Prints the plugin list helper script. The script tag itself lives in the
 * deleteplugin_js template; the values it needs are read from data attributes.
 *
 * @return void
 */
function delete_plugin_admin_footer()
{
    global $mybb, $lang, $templates, $delete_plugin_on_plugins_page;

    if(empty($delete_plugin_on_plugins_page))
    {
        return;
    }

    $lang->load('delete_plugin');

    $can_delete = delete_plugin_admin_can('delete') ? 1 : 0;
    $can_link = delete_plugin_admin_can('delete_link') ? 1 : 0;

    $delete_plugin_base = 'index.php?module=config-delete_plugin&amp;action=preview&amp;plugin=';
    $delete_plugin_can_delete = $can_delete;
    $delete_plugin_can_link = $can_link;
    $delete_plugin_label = htmlspecialchars_uni($lang->delete_plugin_delete);
    $delete_plugin_version = DELETE_PLUGIN_VERSION;

    eval("\$delete_plugin_config = \"".$templates->get('deleteplugin_config')."\";");

    echo $delete_plugin_config;

    eval("\$delete_plugin_js = \"".$templates->get('deleteplugin_js')."\";");

    echo $delete_plugin_js;
}

/**
 * Reads the plugin settings, falling back to the shipped defaults when the
 * settings group has not been installed yet.
 *
 * @return array Short setting names to values, plus the raw pattern lists
 */
function delete_plugin_get_settings()
{
    global $mybb;

    static $defaults = array(
        'scan_mode'                     => 'balanced',
        'excluded_paths'                => "inc/plugins/temp\nBackup Plugins\nadmin/backups\nuploads\n.git\nnode_modules",
        'included_paths'                => '',
        'include_pluginlibrary'         => '0',
        'include_root_scripts'          => '0',
        'include_external_dependencies' => '0',
        'max_file_size'                 => '0',
        'show_skipped'                  => '1',
        'run_uninstall'                 => '1',
        'remove_data'                   => '1',
        'prune_folders'                 => '1',
    );

    $settings = array();

    foreach($defaults as $key => $default)
    {
        $name = 'delete_plugin_'.$key;

        // An empty stored value is a valid choice, e.g. no excluded paths at all.
        if(isset($mybb->settings[$name]))
        {
            $settings[$key] = $mybb->settings[$name];
        }
        else
        {
            $settings[$key] = $default;
        }
    }

    $settings['scan_mode'] = $settings['scan_mode'] === 'strict' ? 'strict' : 'balanced';
    $settings['excludes'] = delete_plugin_split_patterns($settings['excluded_paths']);
    $settings['includes'] = delete_plugin_split_patterns($settings['included_paths']);

    // Shared dependencies are never removed, so the scan must never accept
    // them even when a stale setting tried to turn them on.
    $settings['include_pluginlibrary'] = '0';
    $settings['include_external_dependencies'] = '0';

    return $settings;
}

/**
 * Creates the settings group from settings.json through PluginLibrary.
 *
 * @return void
 */
function delete_plugin_admin_add_settings()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $raw = @file_get_contents(DELETE_PLUGIN_ROOT.'settings.json');

    if($raw === false)
    {
        return;
    }

    $data = json_decode($raw, true);

    if(!is_array($data) || empty($data['settings']))
    {
        return;
    }

    $list = array();

    foreach($data['settings'] as $setting)
    {
        $name = str_replace('delete_plugin_', '', $setting['name']);

        $list[$name] = array(
            'title'       => $setting['title'],
            'description' => $setting['description'],
            'optionscode' => $setting['optionscode'],
            'value'       => $setting['value'],
            'disporder'   => $setting['disporder'],
        );
    }

    $PL->settings(
        'delete_plugin',
        $data['group']['title'],
        $data['group']['description'],
        $list
    );
}

/**
 * Deletes the settings group.
 *
 * @return void
 */
function delete_plugin_admin_remove_settings()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $PL->settings_delete('delete_plugin', true);
}

/**
 * Registers the admin stylesheet with PluginLibrary so it lands in the MyBB
 * stylesheet cache. No style is ever written into the page head.
 *
 * @return void
 */
function delete_plugin_admin_add_stylesheet()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $css_file = DELETE_PLUGIN_ROOT.'Assets/delete_plugin.css';

    if(!file_exists($css_file))
    {
        return;
    }

    $css = @file_get_contents($css_file);

    if($css === false)
    {
        return;
    }

    $PL->stylesheet('delete_plugin', $css, 'admin/*.php');
}

/**
 * Removes the registered admin stylesheet.
 *
 * @return void
 */
function delete_plugin_admin_remove_stylesheet()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $PL->stylesheet_delete('delete_plugin', false, true);
}

/**
 * Returns the id of the plugin settings group.
 *
 * MyBB edits a settings group by its numeric id, so the preview page needs
 * this value to build a working link.
 *
 * @return int Group id, 0 when the group is missing
 */
function delete_plugin_settings_gid()
{
    global $db;

    $query = $db->simple_select('settinggroups', 'gid', "name='delete_plugin'", array('limit' => 1));
    $row = $db->fetch_array($query);

    return isset($row['gid']) ? (int)$row['gid'] : 0;
}

/**
 * Loads the plugin templates from the Templates folder into the database.
 *
 * Every file in the folder is named deleteplugin_<template>.html. PluginLibrary
 * turns the key into deleteplugin_<template>, so the database name matches the
 * file name and the plugin keeps one clean folder.
 *
 * @return void
 */
function delete_plugin_admin_add_templates()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $files = glob(DELETE_PLUGIN_ROOT.'Templates/*.html');

    if(!is_array($files) || empty($files))
    {
        return;
    }

    $templates = array();

    foreach($files as $file)
    {
        $content = @file_get_contents($file);

        if($content !== false)
        {
            $name = basename($file, '.html');
            $key = str_replace('deleteplugin_', '', $name);

            $templates[$key] = $content;
        }
    }

    if(!empty($templates))
    {
        $PL->templates('deleteplugin', 'Delete Plugin', $templates);
    }
}

/**
 * Removes the plugin templates from the database.
 *
 * @return void
 */
function delete_plugin_admin_remove_templates()
{
    global $PL;

    if(!file_exists(PLUGINLIBRARY))
    {
        return;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    $PL->templates_delete('deleteplugin', true);
}
