<?php
$l['delete_plugin_title'] = 'Delete Plugin';
$l['delete_plugin_desc'] = 'Adds a delete link to each plugin in the plugin manager. An advanced scan shows every file that belongs to the plugin and removes only what you tick, so leftover folders and orphaned data can be cleaned up safely.';
$l['delete_plugin_delete'] = 'Delete';
$l['delete_plugin_delete_files'] = 'Delete selected files';
$l['delete_plugin_success'] = '{1} removed. {2} file(s) deleted, {3} freed. Empty folders removed: {4}. Template groups cleared: {5}. Settings groups cleared: {6}. Files that could not be deleted: {7}.';

$l['delete_plugin_error_noplugin'] = 'No plugin was specified for deletion.';
$l['delete_plugin_error_self'] = 'The Delete Plugin cannot delete itself while it is running. Deactivate it first, then remove it from the plugin manager.';
$l['delete_plugin_error_invalid'] = 'The specified plugin is not valid.';
$l['delete_plugin_error_confirm'] = 'Tick the confirmation box before deleting any file.';
$l['delete_plugin_error_nofiles'] = 'No files were found for the plugin "{1}".';
$l['delete_plugin_error_selection'] = 'No file was selected for deletion.';

$l['can_delete_plugins'] = 'Can delete plugins?';
$l['can_see_delete_link'] = 'Can see the "Delete" link in the plugin list?';
$l['can_manage_delete_plugin'] = 'Can use the Delete Plugin scan page?';
$l['delete_plugin_error_denied'] = 'You do not have permission to perform this action.';

$l['delete_plugin_preview_title'] = 'Delete Plugin Scan';
$l['delete_plugin_version'] = 'version {1}';
$l['delete_plugin_edit_settings'] = 'Delete Plugin Settings';
$l['back_to_plugins'] = 'Back to Plugins';
$l['delete_plugin_nothing_found'] = 'Nothing here.';

$l['delete_plugin_stat_files'] = 'Files';
$l['delete_plugin_stat_size'] = 'Total size';
$l['delete_plugin_stat_mode'] = 'Scan mode';
$l['delete_plugin_stat_codename'] = 'Codename';
$l['delete_plugin_folders_label'] = 'Folders owned by this plugin:';
$l['delete_plugin_no_plugin_folder'] = 'none, the plugin is a single file';
$l['delete_plugin_mode_strict'] = 'Strict';
$l['delete_plugin_mode_balanced'] = 'Balanced';

$l['delete_plugin_included_label'] = 'Files to delete';
$l['delete_plugin_skipped_label'] = 'Files that stay on disk';
$l['delete_plugin_column_note'] = 'Why it is deleted';
$l['delete_plugin_column_skip_note'] = 'Why it is kept';
$l['delete_plugin_column_size'] = 'Size';
$l['delete_plugin_select_all'] = 'Select or deselect every file';
$l['delete_plugin_skipped_hint'] = 'These files were found for this plugin but the scan refuses to touch them. They are shown so nothing disappears without a reason.';

$l['delete_plugin_warning'] = 'This cannot be undone. Untick any file above that must stay on disk.';
$l['delete_plugin_confirm'] = 'I have read the file list and want to delete the ticked files.';
$l['delete_plugin_option_uninstall'] = 'Run the plugin uninstall routine first (drops the database tables it created).';
$l['delete_plugin_option_data'] = 'Remove the plugin template groups and settings groups (PluginLibrary).';
$l['delete_plugin_option_folders'] = 'Remove the plugin folders once they are empty.';

$l['delete_plugin_reason_entry'] = 'Plugin entry file';
$l['delete_plugin_reason_owned'] = 'Inside the plugin folder';
$l['delete_plugin_reason_referenced'] = 'Folder referenced by plugin code';
$l['delete_plugin_reason_verified'] = 'Referenced by plugin code';
$l['delete_plugin_reason_explicit'] = 'Listed by the plugin';
$l['delete_plugin_reason_root_entry'] = 'Entry script in the forum root';
$l['delete_plugin_reason_selected'] = 'Selected by the administrator';

$l['delete_plugin_skip_excluded'] = 'Protected by settings ({1})';
$l['delete_plugin_skip_not_related'] = 'Name matches but the file is not plugin code';
$l['delete_plugin_skip_core'] = 'MyBB core file';
$l['delete_plugin_skip_foreign'] = 'Belongs to another plugin';
$l['delete_plugin_skip_not_code'] = 'Data folder, not plugin code';
$l['delete_plugin_skip_too_large'] = 'Larger than the maximum file size';
$l['delete_plugin_skip_setting'] = 'Turned off in the plugin settings, or a shared file that is never deleted';

$l['delete_plugin_category_plugin'] = 'Plugin code';
$l['delete_plugin_category_language'] = 'Language files';
$l['delete_plugin_category_asset'] = 'Styles and images';
$l['delete_plugin_category_data'] = 'Templates and data files';
$l['delete_plugin_category_dependency'] = 'Dependencies';
