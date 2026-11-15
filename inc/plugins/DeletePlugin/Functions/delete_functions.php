<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Removes plugin files, folders and database traces.
 *
 * Everything here is defensive:
 *
 *  - the plugin can never delete itself while it is running
 *  - PluginLibrary and MyBB core files are always blocked
 *  - a path must resolve inside the forum root and may not be a symlink
 *  - the plugin list cache is cleared before a single file is unlinked
 */

/**
 * True when MyBB currently loads the plugin on every page.
 *
 * @param string $codename Plugin codename
 * @return bool
 */
function delete_plugin_is_active($codename)
{
    global $cache;

    if(!is_object($cache))
    {
        return false;
    }

    $plugins_cache = $cache->read('plugins');

    return !empty($plugins_cache['active'][$codename]);
}

/**
 * Runs the plugin deactivation routine and drops it from the plugin cache.
 *
 * The cache has to be updated before any file is unlinked, otherwise MyBB
 * tries to include a file that no longer exists on the next request.
 *
 * @param string $codename Plugin codename
 * @return void
 */
function delete_plugin_run_deactivate($codename)
{
    global $cache;

    $function = $codename.'_deactivate';

    if(function_exists($function))
    {
        call_user_func($function);
    }

    if(!is_object($cache))
    {
        return;
    }

    $plugins_cache = $cache->read('plugins');

    if(!is_array($plugins_cache))
    {
        $plugins_cache = array();
    }

    if(!isset($plugins_cache['active']) || !is_array($plugins_cache['active']))
    {
        $plugins_cache['active'] = array();
    }

    unset($plugins_cache['active'][$codename]);

    $cache->update('plugins', $plugins_cache);
}

/**
 * Runs the plugin uninstall routine when the plugin is installed.
 *
 * Uninstall runs while the plugin files are still on disk, because a plugin
 * uninstall function usually requires its own Functions folder.
 *
 * @param string $codename Plugin codename
 * @return bool True when an uninstall routine ran
 */
function delete_plugin_run_uninstall($codename)
{
    $installed = $codename.'_is_installed';
    $uninstall = $codename.'_uninstall';

    if(!function_exists($uninstall))
    {
        return false;
    }

    if(function_exists($installed) && !call_user_func($installed))
    {
        return false;
    }

    call_user_func($uninstall);

    return true;
}

/**
 * Removes the plugin row from the plugins table.
 *
 * @param string $codename Plugin codename
 * @return void
 */
function delete_plugin_remove_plugin_row($codename)
{
    global $db;

    if(!is_object($db) || !$db->table_exists('plugins'))
    {
        return;
    }

    $db->delete_query('plugins', "codename='".$db->escape_string($codename)."'");
}

/**
 * Collects the template prefixes and settings groups a plugin uses.
 *
 * The plugin main file is parsed for explicit PluginLibrary calls, so a plugin
 * that registers its templates under a shortened prefix is cleaned correctly.
 * The codename itself is always tried as well.
 *
 * @param string $codename Plugin codename
 * @param array $files Scan file list
 * @return array array('templates' => array, 'settings' => array)
 */
function delete_plugin_detect_prefixes($codename, $files)
{
    $templates = array();
    $settings = array();

    foreach((array)$files as $rel_path)
    {
        if(strpos($rel_path, 'inc/plugins/') !== 0 || strtolower(pathinfo($rel_path, PATHINFO_EXTENSION)) !== 'php')
        {
            continue;
        }

        $content = delete_plugin_read_text($rel_path, 131072);

        if($content === false)
        {
            continue;
        }

        if(preg_match_all('/->templates\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $content, $matches))
        {
            foreach($matches[1] as $prefix)
            {
                $templates[$prefix] = true;
            }
        }

        if(preg_match_all('/->settings\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $content, $matches))
        {
            foreach($matches[1] as $group)
            {
                $settings[$group] = true;
            }
        }
    }

    $templates[delete_plugin_slug($codename)] = true;
    $settings[$codename] = true;

    return array(
        'templates' => array_keys(array_filter($templates, 'strlen')),
        'settings'  => array_keys(array_filter($settings, 'strlen')),
    );
}

/**
 * Deletes template groups and settings groups through PluginLibrary.
 *
 * @param array $prefixes Prefixes returned by delete_plugin_detect_prefixes()
 * @return array array('templates' => int, 'settings' => int) groups handled
 */
function delete_plugin_remove_plugin_data($prefixes)
{
    global $PL;

    $done = array('templates' => 0, 'settings' => 0);

    if(!file_exists(PLUGINLIBRARY))
    {
        return $done;
    }

    require_once PLUGINLIBRARY;

    if(!is_object($PL))
    {
        $PL = new PluginLibrary();
    }

    foreach((array)$prefixes['templates'] as $prefix)
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/', $prefix))
        {
            continue;
        }

        $PL->templates_delete($prefix, false);
        $done['templates']++;
    }

    foreach((array)$prefixes['settings'] as $group)
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/', $group))
        {
            continue;
        }

        $PL->settings_delete($group, false);
        $done['settings']++;
    }

    return $done;
}

/**
 * Validates a forum relative path before it is unlinked.
 *
 * @param string $rel_path Forum relative path
 * @return string Clean path, or '' when the path must not be touched
 */
function delete_plugin_safe_file($rel_path)
{
    $rel_path = delete_plugin_clean_path($rel_path);

    if($rel_path === '')
    {
        return '';
    }

    // Never remove the running plugin, the shared library or MyBB core code.
    if($rel_path === 'inc/plugins/delete_plugin.php' || $rel_path === 'inc/plugins/pluginlibrary.php')
    {
        return '';
    }

    // The Delete Plugin folder is always off limits, even when an odd codename
    // would make the scanner claim it as a plugin folder.
    if(strpos($rel_path, 'inc/plugins/DeletePlugin/') === 0)
    {
        return '';
    }

    if(delete_plugin_is_core_path($rel_path))
    {
        return '';
    }

    $full = MYBB_ROOT.$rel_path;

    if(is_link($full) || !is_file($full))
    {
        return '';
    }

    $root = realpath(MYBB_ROOT);
    $real = realpath($full);

    if($root === false || $real === false)
    {
        return '';
    }

    $root = rtrim(str_replace('\\', '/', $root), '/');

    if(strpos(str_replace('\\', '/', $real), $root.'/') !== 0)
    {
        return '';
    }

    return $rel_path;
}

/**
 * Unlinks a list of validated files.
 *
 * @param array $files Forum relative file paths
 * @return array array('deleted' => int, 'bytes' => int, 'errors' => array)
 */
function delete_plugin_remove_files($files)
{
    $result = array(
        'deleted' => 0,
        'bytes'   => 0,
        'errors'  => array(),
    );

    foreach((array)$files as $rel_path)
    {
        $rel_path = delete_plugin_safe_file($rel_path);

        if($rel_path === '')
        {
            continue;
        }

        $full = MYBB_ROOT.$rel_path;
        $size = (int)@filesize($full);

        if(@unlink($full))
        {
            $result['deleted']++;
            $result['bytes'] += $size;
        }
        else
        {
            $result['errors'][] = $rel_path;
        }
    }

    return $result;
}

/**
 * Removes the directories of a plugin once they are empty.
 *
 * @param array $folders Forum relative folders owned by the plugin
 * @return int Number of removed folders
 */
function delete_plugin_prune_folders($folders)
{
    $removed = 0;

    foreach((array)$folders as $rel_dir)
    {
        delete_plugin_prune_folder($rel_dir, $removed);
    }

    return $removed;
}

/**
 * Recursively removes empty directories below one plugin folder.
 *
 * @param string $rel_dir Forum relative folder
 * @param int $removed Counter, incremented in place
 * @return void
 */
function delete_plugin_prune_folder($rel_dir, &$removed)
{
    $rel_dir = rtrim(delete_plugin_clean_path($rel_dir), '/');

    if($rel_dir === '' || is_link(MYBB_ROOT.$rel_dir) || !is_dir(MYBB_ROOT.$rel_dir))
    {
        return;
    }

    if(strpos($rel_dir.'/', 'inc/plugins/DeletePlugin/') === 0)
    {
        return;
    }

    $entries = @scandir(MYBB_ROOT.$rel_dir);

    if($entries === false)
    {
        return;
    }

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..' || !is_dir(MYBB_ROOT.$rel_dir.'/'.$entry))
        {
            continue;
        }

        delete_plugin_prune_folder($rel_dir.'/'.$entry, $removed);
    }

    $entries = @scandir(MYBB_ROOT.$rel_dir);

    if($entries === false)
    {
        return;
    }

    $remaining = array_diff($entries, array('.', '..'));

    if(!empty($remaining))
    {
        return;
    }

    if(@rmdir(MYBB_ROOT.$rel_dir))
    {
        $removed++;
    }
}

/**
 * Runs the whole removal chain for one plugin.
 *
 * @param string $codename Plugin codename
 * @param array $files Files the administrator ticked for removal
 * @param array $scan Scan result
 * @param array $options uninstall, remove_data and prune flags
 * @return array Summary used for the flash message
 */
function delete_plugin_perform($codename, $files, $scan, $options)
{
    $codename = delete_plugin_normalize_codename($codename);

    $result = array(
        'deleted'   => 0,
        'bytes'     => 0,
        'folders'   => 0,
        'templates' => 0,
        'settings'  => 0,
        'errors'    => array(),
        'uninstall' => false,
    );

    if($codename === '' || $codename === 'delete_plugin')
    {
        return $result;
    }

    $prefixes = delete_plugin_detect_prefixes($codename, $scan['files']);

    // The plugin cache is updated before any unlink, so a deleted plugin can
    // never break the next request with a missing include.
    if(delete_plugin_is_active($codename))
    {
        delete_plugin_run_deactivate($codename);
    }

    if(!empty($options['uninstall']))
    {
        $result['uninstall'] = delete_plugin_run_uninstall($codename);
    }

    $removed = delete_plugin_remove_files($files);

    $result['deleted'] = $removed['deleted'];
    $result['bytes'] = $removed['bytes'];
    $result['errors'] = $removed['errors'];

    if(!empty($options['prune']))
    {
        $result['folders'] = delete_plugin_prune_folders($scan['owned_folders']);
    }

    if(!empty($options['remove_data']))
    {
        $data = delete_plugin_remove_plugin_data($prefixes);

        $result['templates'] = $data['templates'];
        $result['settings'] = $data['settings'];
    }

    delete_plugin_remove_plugin_row($codename);

    return $result;
}
