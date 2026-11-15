<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Human readable label for an acceptance reason.
 *
 * @param string $reason Reason key
 * @return string
 */
function delete_plugin_reason_label($reason)
{
    global $lang;

    $lang->load('delete_plugin');

    switch($reason)
    {
        case 'entry':
            return $lang->delete_plugin_reason_entry;
        case 'owned':
            return $lang->delete_plugin_reason_owned;
        case 'referenced':
            return $lang->delete_plugin_reason_referenced;
        case 'verified':
            return $lang->delete_plugin_reason_verified;
        case 'explicit':
            return $lang->delete_plugin_reason_explicit;
        case 'root_entry':
            return $lang->delete_plugin_reason_root_entry;
        case 'selected':
            return $lang->delete_plugin_reason_selected;
        default:
            return $reason;
    }
}

/**
 * Human readable label for a skip reason.
 *
 * @param string $reason Reason key
 * @param string $detail Matching exclusion pattern when relevant
 * @return string
 */
function delete_plugin_skip_label($reason, $detail = '')
{
    global $lang;

    $lang->load('delete_plugin');

    switch($reason)
    {
        case 'excluded':
            return $lang->sprintf($lang->delete_plugin_skip_excluded, $detail);
        case 'not_related':
            return $lang->delete_plugin_skip_not_related;
        case 'core':
            return $lang->delete_plugin_skip_core;
        case 'foreign_plugin':
            return $lang->delete_plugin_skip_foreign;
        case 'not_code':
            return $lang->delete_plugin_skip_not_code;
        case 'too_large':
            return $lang->delete_plugin_skip_too_large;
        case 'setting':
            return $lang->delete_plugin_skip_setting;
        default:
            return $reason;
    }
}

/**
 * Human readable label for a category.
 *
 * @param string $category Category key
 * @return string
 */
function delete_plugin_category_label($category)
{
    global $lang;

    $lang->load('delete_plugin');

    switch($category)
    {
        case 'plugin':
            return $lang->delete_plugin_category_plugin;
        case 'language':
            return $lang->delete_plugin_category_language;
        case 'asset':
            return $lang->delete_plugin_category_asset;
        case 'data':
            return $lang->delete_plugin_category_data;
        case 'dependency':
            return $lang->delete_plugin_category_dependency;
        default:
            return $category;
    }
}

/**
 * Renders the removable files as table rows, each with a delete checkbox.
 *
 * @param array $rows List of array('file', 'note', 'size')
 * @return string
 */
function delete_plugin_render_rows($rows)
{
    global $lang, $templates;

    $html = '';
    $i = 0;

    foreach($rows as $row)
    {
        $delete_plugin_row_class = ($i % 2) ? ' class="alt_row"' : '';
        $delete_plugin_row_file = htmlspecialchars_uni($row['file']);
        $delete_plugin_row_note = htmlspecialchars_uni($row['note']);
        $delete_plugin_row_size = htmlspecialchars_uni($row['size']);
        $i++;

        eval("\$html .= \"".$templates->get('deleteplugin_file_row')."\";");
    }

    if($html === '')
    {
        $html = '<tr class="alt_row"><td colspan="4">'.$lang->delete_plugin_nothing_found.'</td></tr>';
    }

    return $html;
}

/**
 * Renders the files the scan refuses to remove.
 *
 * @param array $rows List of array('file', 'note')
 * @return string
 */
function delete_plugin_render_skipped_rows($rows)
{
    global $lang, $templates;

    $html = '';
    $i = 0;

    foreach($rows as $row)
    {
        $delete_plugin_row_class = ($i % 2) ? ' class="alt_row"' : '';
        $delete_plugin_row_file = htmlspecialchars_uni($row['file']);
        $delete_plugin_row_note = htmlspecialchars_uni($row['note']);
        $i++;

        eval("\$html .= \"".$templates->get('deleteplugin_skipped_row')."\";");
    }

    if($html === '')
    {
        $html = '<tr class="alt_row"><td colspan="3">'.$lang->delete_plugin_nothing_found.'</td></tr>';
    }

    return $html;
}

/**
 * Builds the scan page.
 *
 * @param array $scan Scan result
 * @param string $codename Plugin codename
 * @return string Page HTML
 */
function delete_plugin_build_preview($scan, $codename)
{
    global $mybb, $lang, $templates;

    $lang->load('delete_plugin');

    $settings = $scan['settings'];
    $version = $scan['plugin_version'];
    $counts = delete_plugin_category_counts($scan);

    $delete_plugin_plugin_name = htmlspecialchars_uni($scan['plugin_name']).($version !== '' ? ' <span class="smalltext">'.$lang->sprintf($lang->delete_plugin_version, htmlspecialchars_uni($version)).'</span>' : '');
    $delete_plugin_codename = htmlspecialchars_uni($codename);
    $delete_plugin_codename_raw = htmlspecialchars_uni($codename);
    $delete_plugin_file_count = count($scan['files']);
    $delete_plugin_total_size = delete_plugin_format_size($scan['total_size']);
    $delete_plugin_skip_count = count($scan['skipped']);
    $delete_plugin_folders = '';

    foreach($scan['owned_folders'] as $folder)
    {
        $delete_plugin_folders .= '<li><code>'.htmlspecialchars_uni($folder).'</code></li>';
    }

    if($delete_plugin_folders === '')
    {
        $delete_plugin_folders = '<li>'.$lang->delete_plugin_no_plugin_folder.'</li>';
    }

    $delete_plugin_mode = $lang->delete_plugin_mode_strict;

    if($scan['scan_mode'] === 'balanced')
    {
        $delete_plugin_mode = $lang->delete_plugin_mode_balanced;
    }

    $included_rows = array();

    foreach($scan['files'] as $rel_path)
    {
        $included_rows[] = array(
            'file' => $rel_path,
            'note' => delete_plugin_reason_label($scan['file_reasons'][$rel_path]),
            'size' => delete_plugin_format_size($scan['sizes'][$rel_path]),
        );
    }

    $delete_plugin_file_rows = delete_plugin_render_rows($included_rows);

    $delete_plugin_included_summary = '';

    foreach($counts as $category => $count)
    {
        if($count === 0)
        {
            continue;
        }

        if($delete_plugin_included_summary !== '')
        {
            $delete_plugin_included_summary .= ' &middot; ';
        }

        $delete_plugin_included_summary .= delete_plugin_category_label($category).': '.$count;
    }

    $delete_plugin_skipped_table = '';

    if($scan['skipped'] && !empty($settings['show_skipped']))
    {
        $skipped_rows = array();

        foreach($scan['skipped'] as $rel_path => $reason)
        {
            $detail = isset($scan['skip_detail'][$rel_path]) ? $scan['skip_detail'][$rel_path] : '';

            if(is_int($rel_path))
            {
                $rel_path = $reason;
                $reason = 'excluded';
            }

            $skipped_rows[] = array(
                'file' => $rel_path,
                'note' => delete_plugin_skip_label($reason, $detail),
            );
        }

        $delete_plugin_skipped_rows = delete_plugin_render_skipped_rows($skipped_rows);
        $delete_plugin_skip_count = count($skipped_rows);

        // The skipped template uses row variables, so evaluate it here, in the
        // scope that owns them, before the outer template is built.
        eval("\$delete_plugin_skipped_table = \"".$templates->get('deleteplugin_skipped')."\";");
    }

    $delete_plugin_settings_link = '<a href="'.htmlspecialchars_uni('index.php?module=config-settings').'">'.$lang->delete_plugin_edit_settings.'</a>';

    $gid = delete_plugin_settings_gid();

    if($gid > 0)
    {
        $delete_plugin_settings_link = '<a href="'.htmlspecialchars_uni('index.php?module=config-settings&action=change&gid='.$gid).'">'.$lang->delete_plugin_edit_settings.'</a>';
    }

    $delete_plugin_plugins_link = '<a href="'.htmlspecialchars_uni('index.php?module=config-plugins').'">'.$lang->back_to_plugins.'</a>';

    $delete_plugin_run_uninstall = !empty($settings['run_uninstall']) ? ' checked="checked"' : '';
    $delete_plugin_remove_data = !empty($settings['remove_data']) ? ' checked="checked"' : '';
    $delete_plugin_prune_folders = !empty($settings['prune_folders']) ? ' checked="checked"' : '';

    eval("\$delete_plugin_content = \"".$templates->get('deleteplugin_preview')."\";");

    return $delete_plugin_content;
}

/**
 * Prints the link to the plugin stylesheet.
 *
 * The Admin CP builds its head from files in admin/styles/ and never reads the
 * themestylesheets table, so the link is printed directly after the ACP header.
 *
 * @return void
 */
function delete_plugin_output_stylesheet()
{
    global $mybb;

    $file = DELETE_PLUGIN_ROOT.'Assets/delete_plugin.css';

    if(!file_exists($file))
    {
        return;
    }

    $url = htmlspecialchars_uni($mybb->settings['bburl'].'/inc/plugins/DeletePlugin/Assets/delete_plugin.css?ver='.(int)@filemtime($file));

    echo "\t<link rel=\"stylesheet\" type=\"text/css\" href=\"{$url}\" />\n";
}

/**
 * Prints the scan page helper script.
 *
 * The Admin CP footer hook only loads the script on the plugin list, so the
 * scan page prints it here.
 *
 * @return void
 */
function delete_plugin_output_script()
{
    global $mybb;

    $file = DELETE_PLUGIN_ROOT.'Assets/delete_plugin.js';

    if(!file_exists($file))
    {
        return;
    }

    $url = htmlspecialchars_uni($mybb->settings['bburl'].'/inc/plugins/DeletePlugin/Assets/delete_plugin.js?ver='.(int)@filemtime($file));

    echo "\t<script type=\"text/javascript\" src=\"{$url}\"></script>\n";
}

/**
 * Prints the scan page.
 *
 * @param array $scan Scan result
 * @param string $codename Plugin codename
 * @return void
 */
function delete_plugin_output_preview($scan, $codename)
{
    global $page, $lang;

    $lang->load('delete_plugin');

    $page->add_breadcrumb_item($lang->delete_plugin_title, 'index.php?module=config-delete_plugin');
    $page->add_breadcrumb_item(htmlspecialchars_uni($scan['plugin_name']));
    $page->output_header($lang->delete_plugin_preview_title);

    delete_plugin_output_stylesheet();
    delete_plugin_output_script();

    echo delete_plugin_build_preview($scan, $codename);

    $page->output_footer();
    exit;
}
