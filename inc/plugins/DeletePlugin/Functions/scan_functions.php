<?php
if(!defined('IN_MYBB'))
{
    die('Direct initialization of this file is not allowed.');
}

/**
 * Advanced scanner for the Delete Plugin.
 *
 * The scan never trusts file names alone. Every file that may be removed must
 * be backed by evidence:
 *
 *   tier 1  ownership      the file lives inside the plugin's own folder
 *   tier 2  reference      plugin code loads or points at the file
 *   tier 3  verified name  the name matches and the content really talks
 *                          about the plugin
 *
 * Anything else is reported as kept, with a reason, so the admin page shows
 * exactly what will be removed and what will be left on disk.
 */

/**
 * Normalises a plugin codename so it is safe to use in paths and queries.
 *
 * @param string $codename Raw codename
 * @return string Clean codename
 */
function delete_plugin_normalize_codename($codename)
{
    $codename = (string)$codename;
    $codename = str_replace(array('.', '/', '\\', "\0"), '', $codename);
    $codename = basename($codename);

    if(!preg_match('/^[A-Za-z0-9_\-]+$/', $codename))
    {
        return '';
    }

    return $codename;
}

/**
 * Reduces a path segment to letters and digits for fuzzy comparison.
 *
 * @param string $value Segment to reduce
 * @return string
 */
function delete_plugin_slug($value)
{
    return preg_replace('/[^a-z0-9]+/', '', strtolower((string)$value));
}

/**
 * Normalises a forum relative path: forward slashes, no leading slash, no
 * trailing slash and no "." or ".." leftovers.
 *
 * @param string $path Raw path
 * @return string Clean path, or '' when the path escapes the forum root
 */
function delete_plugin_clean_path($path)
{
    $path = str_replace('\\', '/', trim((string)$path));
    $path = preg_replace('#/+#', '/', $path);

    if($path === '' || $path === '/' || strpos($path, "\0") !== false)
    {
        return '';
    }

    $path = ltrim($path, '/');
    $absolute = false;

    if(substr($path, 0, 2) === './')
    {
        $path = substr($path, 2);
    }

    $segments = array();
    foreach(explode('/', $path) as $segment)
    {
        if($segment === '' || $segment === '.')
        {
            continue;
        }

        if($segment === '..')
        {
            if(empty($segments))
            {
                return '';
            }

            array_pop($segments);
            continue;
        }

        $segments[] = $segment;
    }

    $clean = implode('/', $segments);

    if($clean === '' || $absolute)
    {
        return '';
    }

    return $clean;
}

/**
 * Splits the settings textarea into a list of trimmed, non empty patterns.
 *
 * @param string $raw Raw setting value
 * @return array
 */
function delete_plugin_split_patterns($raw)
{
    $patterns = array();

    foreach(preg_split('/[\r\n,]+/', (string)$raw) as $line)
    {
        $line = trim($line);

        if($line === '')
        {
            continue;
        }

        $line = str_replace('\\', '/', $line);
        $line = preg_replace('#^(\./|/)#', '', $line);

        $patterns[] = rtrim($line, '/');
    }

    return $patterns;
}

/**
 * Tests a path against a list of patterns. A directory pattern also matches
 * everything below it.
 *
 * @param string $path Forum relative path
 * @param array $patterns Patterns from the settings
 * @return string|false The matching pattern, or false when nothing matched
 */
function delete_plugin_match_patterns($path, $patterns)
{
    $path = delete_plugin_clean_path($path);

    if($path === '')
    {
        return false;
    }

    $basename = basename($path);

    foreach($patterns as $pattern)
    {
        if($pattern === '')
        {
            continue;
        }

        $pattern = rtrim($pattern, '/');

        if(strcasecmp($pattern, $path) === 0)
        {
            return $pattern;
        }

        if(strncasecmp($path, $pattern, strlen($pattern)) === 0)
        {
            $next = substr($path, strlen($pattern));

            if($next === '' || $next[0] === '/')
            {
                return $pattern;
            }
        }

        if(fnmatch($pattern, $path))
        {
            return $pattern;
        }

        if(strpos($pattern, '/') === false && fnmatch($pattern, $basename))
        {
            return $pattern;
        }
    }

    return false;
}

/**
 * Reads a file as UTF-8 safe text. Binary files return false so they are never
 * matched on content.
 *
 * @param string $rel_path Forum relative path
 * @param int $max_bytes Maximum bytes to read
 * @return string|false
 */
function delete_plugin_read_text($rel_path, $max_bytes = 262144)
{
    static $cache = array();

    $rel_path = delete_plugin_clean_path($rel_path);

    if(array_key_exists($rel_path, $cache))
    {
        return $cache[$rel_path];
    }

    $full = MYBB_ROOT.$rel_path;

    if(!is_file($full) || !is_readable($full))
    {
        $cache[$rel_path] = false;
        return false;
    }

    $size = @filesize($full);

    if($size === false || $size == 0)
    {
        $cache[$rel_path] = false;
        return false;
    }

    $handle = @fopen($full, 'rb');

    if(!$handle)
    {
        $cache[$rel_path] = false;
        return false;
    }

    $data = fread($handle, min($size, $max_bytes));
    fclose($handle);

    if($data === false || $data === '')
    {
        $cache[$rel_path] = false;
        return false;
    }

    if(strpos($data, "\0") !== false)
    {
        $cache[$rel_path] = false;
        return false;
    }

    $cache[$rel_path] = $data;
    return $data;
}

/**
 * Only text files are worth parsing for references.
 *
 * @param string $rel_path Forum relative path
 * @return bool
 */
function delete_plugin_is_scannable($rel_path)
{
    $ext = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION));

    return in_array($ext, array('php', 'phtml', 'js', 'css', 'html', 'htm', 'json', 'txt', 'sql', 'xml', 'md', 'htaccess'), true)
        || basename($rel_path) === '.htaccess';
}

/**
 * Folders that belong to the plugin by name.
 *
 * Handles the two conventions in use: a folder named exactly after the
 * codename (Market) and a nested folder whose name is the tail of the codename
 * (ougc_pages uses inc/plugins/ougc/Pages).
 *
 * @param string $codename Plugin codename
 * @return array Forum relative folder paths
 */
function delete_plugin_find_owned_folders($codename)
{
    $folders = array();
    $slug = delete_plugin_slug($codename);

    if($slug === '')
    {
        return $folders;
    }

    $base = 'inc/plugins';

    if(!is_dir(MYBB_ROOT.$base))
    {
        return $folders;
    }

    $entries = @scandir(MYBB_ROOT.$base);

    if($entries === false)
    {
        return $folders;
    }

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..')
        {
            continue;
        }

        $path = $base.'/'.$entry;

        if(!is_dir(MYBB_ROOT.$path))
        {
            continue;
        }

        if(delete_plugin_folder_slug_matches($slug, $entry))
        {
            $folders[] = $path;
            continue;
        }

        $children = @scandir(MYBB_ROOT.$path);

        if($children === false)
        {
            continue;
        }

        foreach($children as $child)
        {
            if($child === '.' || $child === '..')
            {
                continue;
            }

            $child_path = $path.'/'.$child;

            if(is_dir(MYBB_ROOT.$child_path) && delete_plugin_folder_slug_matches($slug, $child))
            {
                $folders[] = $child_path;
            }
        }
    }

    return $folders;
}

/**
 * True when a folder name identifies it as the plugin's own folder.
 *
 * @param string $slug Slugged codename
 * @param string $folder_name Folder name
 * @return bool
 */
function delete_plugin_folder_slug_matches($slug, $folder_name)
{
    $folder_slug = delete_plugin_slug($folder_name);

    if($folder_slug === '' || strlen($folder_slug) < 3)
    {
        return false;
    }

    if($folder_slug === $slug)
    {
        return true;
    }

    return substr($slug, -strlen($folder_slug)) === $folder_slug;
}

/**
 * True when the folder path sits inside one of the plugin's own folders.
 *
 * @param array $folders Owned folders
 * @param string $rel_path Forum relative path
 * @return bool
 */
function delete_plugin_in_owned_folder($folders, $rel_path)
{
    $rel_path = delete_plugin_clean_path($rel_path);

    foreach($folders as $folder)
    {
        if($rel_path === $folder || strpos($rel_path, $folder.'/') === 0)
        {
            return true;
        }
    }

    return false;
}

/**
 * Core MyBB files are never removed, whatever plugin code points at.
 *
 * @param string $rel_path Forum relative path
 * @return bool
 */
function delete_plugin_is_core_path($rel_path)
{
    static $core_files = array(
        'global.php', 'index.php', 'member.php', 'misc.php', 'showthread.php', 'forumdisplay.php',
        'search.php', 'usercp.php', 'modcp.php', 'portal.php', 'calendar.php', 'announcements.php',
        'attachment.php', 'captcha.php', 'contact.php', 'cron.php', 'download.php', 'editpoll.php',
        'functions.php', 'printthreads.php', 'redirect.php', 'report.php', 'rewards.php', 'robots.txt',
        'session.php', 'sitemap.php', 'stats.php', 'task.php', 'thankyou.php', 'waisql.php'
    );

    static $core_dirs = array(
        'inc/adminfunctions', 'inc/ajax', 'inc/cache', 'inc/class_', 'inc/datahandlers', 'inc/db_',
        'inc/functions_', 'inc/languages/english/editor.js', 'inc/plugins/temp', 'inc/sessions',
        'inc/settings', 'inc/smilies', 'inc/styles', 'inc/tasks', 'inc/themes', 'inc/uploads',
        'admin/inc', 'admin/modules/forum', 'admin/modules/home', 'admin/modules/style', 'admin/modules/user',
        'jscripts', 'images/smilies', 'images/style', 'archive', 'install'
    );

    if(in_array($rel_path, $core_files, true))
    {
        return true;
    }

    foreach($core_dirs as $dir)
    {
        if(strpos($rel_path, $dir.'/') === 0)
        {
            return true;
        }
    }

    return preg_match('#^inc/class_[a-z_]+\.php$#i', $rel_path) === 1
        || preg_match('#^inc/(functions|init|db_|class_|config|constants)[a-z_]*\.php$#i', $rel_path) === 1;
}

/**
 * True when the path belongs to a plugin other than the one being scanned.
 *
 * @param string $rel_path Forum relative path
 * @param array $owned_folders Owned folders of the current plugin
 * @return bool
 */
function delete_plugin_is_foreign_plugin_path($rel_path, $owned_folders)
{
    if(strpos($rel_path, 'inc/plugins/') !== 0)
    {
        return false;
    }

    if(delete_plugin_in_owned_folder($owned_folders, $rel_path))
    {
        return false;
    }

    return true;
}

/**
 * True when the file name carries the plugin codename as a whole token.
 *
 * @param string $filename File name
 * @param string $codename Plugin codename
 * @return bool
 */
function delete_plugin_name_matches($filename, $codename)
{
    $filename = strtolower(basename((string)$filename));
    $token = strtolower($codename);

    if($token === '' || strlen($token) < 3)
    {
        return false;
    }

    $stem = $filename;

    for($i = 0; $i < 3; $i++)
    {
        $stripped = preg_replace('/\.[a-z0-9]+$/', '', $stem);

        if($stripped === $stem)
        {
            break;
        }

        $stem = $stripped;
    }

    if($stem === $token)
    {
        return true;
    }

    $pattern = '/(^|[^a-z0-9])'.preg_quote($token, '/').'([_\-]|$)/';

    return preg_match($pattern, $filename) === 1;
}

/**
 * True when the content of a file really belongs to the plugin.
 *
 * A matching file name is never enough on its own: the content must reference
 * the plugin prefix, the plugin namespace or the plugin's own folder.
 *
 * @param string $rel_path Forum relative path
 * @param string $codename Plugin codename
 * @param array $owned_folders Owned folders
 * @return bool
 */
function delete_plugin_content_related($rel_path, $codename, $owned_folders = array())
{
    $content = delete_plugin_read_text($rel_path);

    if($content === false)
    {
        return false;
    }

    foreach((array)$owned_folders as $folder)
    {
        if(stripos($content, $folder) !== false)
        {
            return true;
        }
    }

    $token = strtolower($codename);
    $quoted = preg_quote($token, '/');

    $patterns = array(
        '/(^|[^a-z0-9_])'.$quoted.'_[a-z0-9_]+/',
        '/(^|[^a-z0-9_])'.$quoted.'->/',
        '/(^|[^a-z0-9_])'.$quoted.'::/',
    );

    foreach($patterns as $pattern)
    {
        if(preg_match($pattern, strtolower($content)))
        {
            return true;
        }
    }

    if(strpos($token, '_') !== false && stripos($content, str_replace('_', '\\', $token)) !== false)
    {
        return true;
    }

    return false;
}

/**
 * True when the file uses the plugin namespace, e.g. the plugin "ougc_pages"
 * shipping "use function ougc\\Pages\\Core\\initRun" in a forum side script.
 *
 * Only prefixed codenames can own a namespace, so single word plugins never
 * match on this signal.
 *
 * @param string $rel_path Forum relative path
 * @param string $codename Plugin codename
 * @return bool
 */
function delete_plugin_namespace_related($rel_path, $codename)
{
    if(strpos($codename, '_') === false)
    {
        return false;
    }

    $content = delete_plugin_read_text($rel_path);

    if($content === false)
    {
        return false;
    }

    return stripos($content, str_replace('_', '\\', strtolower($codename))) !== false;
}

/**
 * Decides whether a quoted string inside plugin code is a forum path.
 *
 * @param string $raw Candidate string
 * @return bool
 */
function delete_plugin_looks_like_path($raw)
{
    $raw = trim((string)$raw);

    if(strlen($raw) < 2 || strlen($raw) > 300)
    {
        return false;
    }

    if(strpos($raw, '://') !== false || strpos($raw, 'data:') !== false || strpos($raw, 'mailto:') !== false)
    {
        return false;
    }

    foreach(array('<', '>', '{', '}', ';', '|', "\n", "\r", "\t", '$') as $blocked)
    {
        if(strpos($raw, $blocked) !== false)
        {
            return false;
        }
    }

    if(preg_match('/\s/', $raw))
    {
        return false;
    }

    $pattern = '/^[A-Za-z0-9_\-\.\/\*]+$/';

    if(!preg_match($pattern, $raw))
    {
        return false;
    }

    if(strpos($raw, '/') !== false)
    {
        return true;
    }

    return preg_match('/\.(php|js|css|html?|json|sql|txt|xml|md|png|jpe?g|gif|svg|webp|ico|wav|mp3|mp4|woff2?|ttf|eot)$/i', $raw) === 1;
}

/**
 * Pulls every path candidate out of a chunk of plugin code.
 *
 * @param string $content File content
 * @return array List of array('raw', 'kind') where kind is include, path or glob
 */
function delete_plugin_extract_references($content)
{
    $references = array();

    if(preg_match_all('/\b(require|require_once|include|include_once)\b\s*\(?\s*([\'"])([^\'"\r\n]+)\2/i', $content, $matches, PREG_SET_ORDER))
    {
        foreach($matches as $match)
        {
            $references[] = array('raw' => $match[3], 'kind' => 'include');
        }
    }

    if(preg_match_all('/([\'"])([^\'"\r\n]{2,300})\1/', $content, $matches, PREG_SET_ORDER))
    {
        foreach($matches as $match)
        {
            $raw = $match[2];

            if(!delete_plugin_looks_like_path($raw))
            {
                continue;
            }

            $kind = strpos($raw, '*') !== false ? 'glob' : 'path';

            $references[] = array('raw' => $raw, 'kind' => $kind);
        }
    }

    return $references;
}

/**
 * Returns the directory with the real on disk capitalisation.
 *
 * Forum paths get written in many ways, e.g. "inc/plugins/quickattach" while
 * the folder on disk is "inc/plugins/QuickAttach". Storing the canonical folder
 * keeps every file listed once, no matter which route found it.
 *
 * Directory listings are expensive on network storage, so a folder reached by
 * the scanner walk is remembered and never looked up again.
 *
 * @param string $rel_dir Forum relative directory
 * @return string
 */
function delete_plugin_real_dir($rel_dir)
{
    static $known = array();

    $rel_dir = rtrim(delete_plugin_clean_path($rel_dir), '/');
    $key = strtolower($rel_dir);

    if(isset($known[$key]))
    {
        return $known[$key];
    }

    $known[$key] = $rel_dir;

    if($rel_dir === '' || !is_dir(MYBB_ROOT.$rel_dir))
    {
        return $rel_dir;
    }

    $parent = dirname($rel_dir);
    $name = basename($rel_dir);

    if($parent === '.' || $parent === '/')
    {
        return $rel_dir;
    }

    $parent_real = delete_plugin_real_dir($parent);
    $entries = @scandir(MYBB_ROOT.$parent_real);

    if($entries === false || in_array($name, $entries, true))
    {
        return $rel_dir;
    }

    foreach($entries as $entry)
    {
        if(strcasecmp($entry, $name) === 0)
        {
            $known[$key] = $parent_real.'/'.$entry;

            return $known[$key];
        }
    }

    return $rel_dir;
}

/**
 * Rewrites the folder part of a path with the on disk capitalisation.
 *
 * @param string $rel_path Forum relative path
 * @return string
 */
function delete_plugin_real_path($rel_path)
{
    $rel_path = delete_plugin_clean_path($rel_path);

    if($rel_path === '')
    {
        return $rel_path;
    }

    $dir = dirname($rel_path);

    if($dir === '.' || $dir === '/')
    {
        return $rel_path;
    }

    $real_dir = delete_plugin_real_dir($dir);

    return $real_dir === $dir ? $rel_path : $real_dir.'/'.basename($rel_path);
}

/**
 * Turns a raw reference into forum relative paths.
 *
 * @param array $ctx Scan context
 * @param string $rel_file File the reference came from
 * @param string $raw Raw reference
 * @return array Forum relative paths
 */
function delete_plugin_resolve_reference(&$ctx, $rel_file, $raw)
{
    $raw = trim(str_replace('\\', '/', $raw));

    if($raw === '' || substr($raw, 0, 1) === '#' || substr($raw, 0, 2) === '//')
    {
        return array();
    }

    $cache_key = $rel_file."\0".$raw;

    if(isset($ctx['ref_cache'][$cache_key]))
    {
        return $ctx['ref_cache'][$cache_key];
    }

    if(strpos($raw, '*') !== false)
    {
        return $ctx['ref_cache'][$cache_key] = delete_plugin_resolve_glob($ctx, $raw);
    }

    $file_dir = dirname($rel_file);
    $candidates = array();

    $absolute = ltrim($raw, '/');

    if(strpos($absolute, 'inc/') === 0 || strpos($absolute, 'admin/') === 0 || strpos($absolute, 'jscripts/') === 0
        || strpos($absolute, 'images/') === 0 || strpos($absolute, 'archive/') === 0 || strpos($absolute, 'uploads/') === 0)
    {
        $candidates[] = $absolute;
    }
    else
    {
        if(strpos($raw, '/') !== 0 && strpos($raw, './') !== 0 && strpos($raw, '../') !== 0)
        {
            $candidates[] = $file_dir.'/'.$raw;
        }

        $candidates[] = $raw;
    }

    foreach($ctx['roots'] as $folder)
    {
        $candidates[] = $folder.'/'.$raw;
    }

    if(substr($raw, 0, 1) === '/')
    {
        $candidates[] = $file_dir.$raw;
    }

    $resolved = array();

    foreach($candidates as $candidate)
    {
        $candidate = delete_plugin_clean_path($candidate);

        if($candidate === '' || in_array($candidate, $resolved, true))
        {
            continue;
        }

        if(is_dir(MYBB_ROOT.$candidate) || is_file(MYBB_ROOT.$candidate))
        {
            $resolved[] = delete_plugin_real_path($candidate);
        }
    }

    return $ctx['ref_cache'][$cache_key] = array_values(array_unique($resolved));
}

/**
 * Expands a wildcard reference such as inc/plugins/Books/Assets/*.css.
 *
 * @param array $ctx Scan context
 * @param string $raw Raw wildcard reference
 * @return array Forum relative paths
 */
function delete_plugin_resolve_glob(&$ctx, $raw)
{
    $raw = ltrim(trim(str_replace('\\', '/', $raw)), '/');
    $matches = @glob(MYBB_ROOT.$raw, GLOB_NOSORT);

    if($matches === false)
    {
        $matches = array();
    }

    $resolved = array();

    foreach($matches as $match)
    {
        $clean = delete_plugin_clean_path(substr($match, strlen(MYBB_ROOT)));

        if($clean !== '')
        {
            $resolved[] = $clean;
        }
    }

    if(!empty($resolved))
    {
        return $resolved;
    }

    $wildcard = strpos($raw, '*');

    if($wildcard !== false)
    {
        $prefix = delete_plugin_clean_path(rtrim(substr($raw, 0, $wildcard), '/'));

        if($prefix !== '' && is_dir(MYBB_ROOT.$prefix))
        {
            return array($prefix);
        }
    }

    return array();
}

/**
 * Adds a file to the removal list when it passes the exclusion and size filters.
 *
 * @param array $ctx Scan context
 * @param string $rel_path Forum relative path
 * @param string $reason Why the file is accepted
 * @param bool $scan Whether to queue the file for reference parsing
 * @return bool True when the file is on the removal list
 */
function delete_plugin_add_file(&$ctx, $rel_path, $reason, $scan = false)
{
    $rel_path = delete_plugin_clean_path($rel_path);

    if($rel_path === '' || isset($ctx['accepted'][$rel_path]))
    {
        return isset($ctx['accepted'][$rel_path]);
    }

    $key = strtolower($rel_path);

    if(isset($ctx['index'][$key]))
    {
        return isset($ctx['accepted'][$ctx['index'][$key]]);
    }

    $ctx['index'][$key] = $rel_path;

    if(!is_file(MYBB_ROOT.$rel_path))
    {
        return false;
    }

    $excluded = delete_plugin_match_patterns($rel_path, $ctx['excludes']);

    if($excluded !== false)
    {
        $ctx['skipped'][$rel_path] = 'excluded';
        $ctx['skip_detail'][$rel_path] = $excluded;
        return false;
    }

    $size = (int)@filesize(MYBB_ROOT.$rel_path);

    // The plugin entry file is required for the plugin to work at all, so
    // the size cap never drops it from the removal list.
    if($ctx['max_size'] > 0 && $size > $ctx['max_size']
        && $reason !== 'entry' && $reason !== 'pluginlibrary')
    {
        $ctx['skipped'][$rel_path] = 'too_large';
        return false;
    }

    unset($ctx['skipped'][$rel_path], $ctx['skip_detail'][$rel_path]);

    $ctx['accepted'][$rel_path] = array(
        'reason'   => $reason,
        'size'     => $size,
        'category' => delete_plugin_category($rel_path, $reason),
    );
    $ctx['total_size'] += $size;

    if($scan && delete_plugin_is_scannable($rel_path))
    {
        $ctx['queue'][] = $rel_path;
    }

    return true;
}

/**
 * Labels a file so the preview page can group it.
 *
 * @param string $rel_path Forum relative path
 * @param string $reason Acceptance reason
 * @return string
 */
function delete_plugin_category($rel_path, $reason)
{
    if($reason === 'dependency' || $reason === 'pluginlibrary')
    {
        return 'dependency';
    }

    if(strpos($rel_path, 'inc/languages/') === 0)
    {
        return 'language';
    }

    if(preg_match('/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|eot|mp3|mp4)$/i', $rel_path))
    {
        return 'asset';
    }

    if(preg_match('/\.(html?|json|sql|xml|txt|md)$/i', $rel_path))
    {
        return 'data';
    }

    return 'plugin';
}

/**
 * Expands an administrator selection of skipped paths into a flat file list.
 *
 * A selected folder contributes every file below it. Symlinked folders are not
 * followed and anything that escapes the forum root is dropped, because the
 * paths come from a browser request.
 *
 * @param array $selected Forum relative paths picked on the scan page
 * @return array Flat, sorted list of forum relative file paths
 */
function delete_plugin_expand_selection($selected)
{
    $files = array();
    $visited = array();

    foreach((array)$selected as $raw)
    {
        if(!is_string($raw) || $raw === '')
        {
            continue;
        }

        $rel_path = delete_plugin_real_path($raw);

        if($rel_path === '')
        {
            continue;
        }

        if(is_file(MYBB_ROOT.$rel_path))
        {
            $files[$rel_path] = true;
            continue;
        }

        if(is_dir(MYBB_ROOT.$rel_path))
        {
            delete_plugin_walk_selection($rel_path, $files, $visited);
        }
    }

    $files = array_keys($files);
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Recursively collects the files of one selected folder.
 *
 * @param string $rel_dir Forum relative folder
 * @param array $files Collected file paths, used as a set
 * @param array $visited Folders already walked
 * @return void
 */
function delete_plugin_walk_selection($rel_dir, &$files, &$visited)
{
    $rel_dir = rtrim((string)$rel_dir, '/');

    if($rel_dir === '' || isset($visited[$rel_dir]) || count($files) > 20000)
    {
        return;
    }

    $visited[$rel_dir] = true;

    $entries = @scandir(MYBB_ROOT.$rel_dir);

    if($entries === false)
    {
        return;
    }

    sort($entries);

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..')
        {
            continue;
        }

        $path = $rel_dir.'/'.$entry;

        if(is_link(MYBB_ROOT.$path))
        {
            continue;
        }

        if(is_dir(MYBB_ROOT.$path))
        {
            delete_plugin_walk_selection($path, $files, $visited);
            continue;
        }

        if(is_file(MYBB_ROOT.$path))
        {
            $files[$path] = true;
        }
    }
}

/**
 * Adds the paths an administrator picked from the skipped list to a scan.
 *
 * The pick overrides the exclusion that produced the skip, so a deliberately
 * selected core, foreign or oversized file is still removed.
 *
 * @param array $scan Scan result, updated in place
 * @param array $selected Forum relative paths picked on the scan page
 * @return int Number of files added
 */
function delete_plugin_merge_selected(&$scan, $selected)
{
    $paths = delete_plugin_expand_selection($selected);

    if(empty($paths))
    {
        return 0;
    }

    $existing = array_flip($scan['files']);
    $added = 0;

    foreach($paths as $rel_path)
    {
        if(isset($existing[$rel_path]))
        {
            continue;
        }

        $size = (int)@filesize(MYBB_ROOT.$rel_path);

        $scan['files'][] = $rel_path;
        $scan['file_reasons'][$rel_path] = 'selected';
        $scan['categories'][$rel_path] = delete_plugin_category($rel_path, 'selected');
        $scan['sizes'][$rel_path] = $size;
        $scan['total_size'] += $size;
        $added++;
    }

    foreach((array)$selected as $raw)
    {
        if(!is_string($raw))
        {
            continue;
        }

        $clean = delete_plugin_real_path($raw);

        if($clean !== '')
        {
            unset($scan['skipped'][$clean], $scan['skip_detail'][$clean]);
        }
    }

    if($added > 0)
    {
        sort($scan['files'], SORT_STRING);
    }

    return $added;
}

/**
 * Walks a folder and adds everything inside it to the removal list.
 *
 * @param array $ctx Scan context
 * @param string $rel_dir Forum relative folder
 * @param string $reason Acceptance reason for the files
 * @return void
 */
function delete_plugin_walk(&$ctx, $rel_dir, $reason)
{
    $rel_dir = rtrim(delete_plugin_clean_path($rel_dir), '/');
    $rel_dir = delete_plugin_real_dir($rel_dir);

    if($rel_dir === '' || isset($ctx['walked'][$rel_dir]))
    {
        return;
    }

    if(!is_dir(MYBB_ROOT.$rel_dir))
    {
        return;
    }

    $excluded = delete_plugin_match_patterns($rel_dir, $ctx['excludes']);

    if($excluded !== false)
    {
        $ctx['skipped'][$rel_dir] = 'excluded';
        $ctx['skip_detail'][$rel_dir] = $excluded;
        return;
    }

    $ctx['walked'][$rel_dir] = true;
    delete_plugin_real_dir($rel_dir);

    $entries = @scandir(MYBB_ROOT.$rel_dir);

    if($entries === false)
    {
        return;
    }

    sort($entries);

    $sub_dirs = array();

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..')
        {
            continue;
        }

        $path = $rel_dir.'/'.$entry;

        if(is_dir(MYBB_ROOT.$path))
        {
            if(!is_link(MYBB_ROOT.$path))
            {
                $sub_dirs[] = $path;
            }

            continue;
        }

        delete_plugin_add_file($ctx, $path, $reason, true);
    }

    foreach($sub_dirs as $sub_dir)
    {
        delete_plugin_walk($ctx, $sub_dir, $reason);
    }
}

/**
 * Accepts or rejects a referenced folder.
 *
 * Upload folders, cache folders and anything outside inc/plugins are never
 * removed, even when plugin code points at them.
 *
 * @param array $ctx Scan context
 * @param string $rel_dir Forum relative folder
 * @param string $kind Reference kind
 * @return void
 */
function delete_plugin_add_folder(&$ctx, $rel_dir, $kind)
{
    $rel_dir = rtrim(delete_plugin_clean_path($rel_dir), '/');

    if($rel_dir === '' || isset($ctx['walked'][$rel_dir]))
    {
        return;
    }

    if(!is_dir(MYBB_ROOT.$rel_dir))
    {
        return;
    }

    $excluded = delete_plugin_match_patterns($rel_dir, $ctx['excludes']);

    if($excluded !== false)
    {
        $ctx['skipped'][$rel_dir] = 'excluded';
        $ctx['skip_detail'][$rel_dir] = $excluded;
        return;
    }

    $explicit = delete_plugin_match_patterns($rel_dir, $ctx['includes']) !== false;
    $inside_owned = delete_plugin_in_owned_folder($ctx['owned_folders'], $rel_dir);
    $named_after_plugin = delete_plugin_folder_slug_matches(delete_plugin_slug($ctx['codename']), basename($rel_dir));

    $in_plugin_area = strpos($rel_dir, 'inc/plugins/') === 0 && $named_after_plugin;

    if(!$explicit && !$inside_owned && !$in_plugin_area)
    {
        $ctx['skipped'][$rel_dir] = 'not_code';
        return;
    }

    $reason = ($explicit || $inside_owned) ? 'owned' : 'referenced';

    $ctx['roots'][$rel_dir] = true;

    delete_plugin_walk($ctx, $rel_dir, $reason);
}

/**
 * Accepts or rejects a referenced file.
 *
 * @param array $ctx Scan context
 * @param string $rel_path Forum relative path
 * @param string $kind Reference kind: include, path or glob
 * @return bool
 */
function delete_plugin_consider_file(&$ctx, $rel_path, $kind)
{
    $rel_path = delete_plugin_clean_path($rel_path);

    if($rel_path === '' || isset($ctx['accepted'][$rel_path]) || isset($ctx['considered'][$rel_path]))
    {
        return isset($ctx['accepted'][$rel_path]);
    }

    $ctx['considered'][$rel_path] = true;

    if(!is_file(MYBB_ROOT.$rel_path))
    {
        return false;
    }

    if(delete_plugin_match_patterns($rel_path, $ctx['excludes']) !== false)
    {
        $ctx['skipped'][$rel_path] = 'excluded';
        return false;
    }

    if(delete_plugin_in_owned_folder($ctx['owned_folders'], $rel_path))
    {
        return delete_plugin_add_file($ctx, $rel_path, 'owned', true);
    }

    if(delete_plugin_match_patterns($rel_path, $ctx['includes']) !== false)
    {
        return delete_plugin_add_file($ctx, $rel_path, 'explicit', true);
    }

    if($rel_path === 'inc/plugins/pluginlibrary.php')
    {
        $ctx['uses_pluginlibrary'] = true;

        if(!empty($ctx['settings']['include_pluginlibrary']))
        {
            return delete_plugin_add_file($ctx, $rel_path, 'pluginlibrary');
        }

        $ctx['skipped'][$rel_path] = 'setting';
        return false;
    }

    if(delete_plugin_is_core_path($rel_path))
    {
        $ctx['skipped'][$rel_path] = 'core';
        return false;
    }

    if(delete_plugin_is_foreign_plugin_path($rel_path, $ctx['owned_folders']))
    {
        if(!empty($ctx['settings']['include_external_dependencies'])
            && ($kind === 'include' || $kind === 'path' || $kind === 'glob'))
        {
            return delete_plugin_add_file($ctx, $rel_path, 'dependency', true);
        }

        $ctx['skipped'][$rel_path] = 'foreign_plugin';
        return false;
    }

    $name_matches = delete_plugin_name_matches(basename($rel_path), $ctx['codename']);

    if($name_matches)
    {
        if($kind === 'include' || $ctx['scan_mode'] !== 'strict')
        {
            if($kind === 'include' || delete_plugin_content_related($rel_path, $ctx['codename'], $ctx['owned_folders']))
            {
                return delete_plugin_add_file($ctx, $rel_path, 'verified', true);
            }
        }

        $ctx['skipped'][$rel_path] = 'not_related';
        return false;
    }

    if($kind === 'include')
    {
        return delete_plugin_add_file($ctx, $rel_path, 'verified', true);
    }

    $ctx['skipped'][$rel_path] = 'not_related';
    return false;
}

/**
 * Parses one queued file and applies every reference it finds.
 *
 * @param array $ctx Scan context
 * @param string $rel_path Forum relative path
 * @return void
 */
function delete_plugin_process_file(&$ctx, $rel_path)
{
    $content = delete_plugin_read_text($rel_path);

    if($content === false)
    {
        return;
    }

    if(delete_plugin_content_uses_pluginlibrary($content))
    {
        $ctx['uses_pluginlibrary'] = true;
    }

    foreach(delete_plugin_extract_references($content) as $reference)
    {
        $targets = delete_plugin_resolve_reference($ctx, $rel_path, $reference['raw']);

        foreach($targets as $target)
        {
            if(is_dir(MYBB_ROOT.$target))
            {
                delete_plugin_add_folder($ctx, $target, $reference['kind']);
                continue;
            }

            delete_plugin_consider_file($ctx, $target, $reference['kind']);
        }
    }
}

/**
 * Sweeps the plugin area and the language folders for files whose name carries
 * the codename, then keeps only the ones whose content confirms the match.
 *
 * @param array $ctx Scan context
 * @return void
 */
function delete_plugin_sweep_named_files(&$ctx)
{
    $entries = @scandir(MYBB_ROOT.'inc/plugins');

    if($entries !== false)
    {
        foreach($entries as $entry)
        {
            if($entry === '.' || $entry === '..' || is_dir(MYBB_ROOT.'inc/plugins/'.$entry))
            {
                continue;
            }

            delete_plugin_sweep_one($ctx, 'inc/plugins/'.$entry);
        }
    }

    $language_root = @scandir(MYBB_ROOT.'inc/languages');

    if($language_root === false)
    {
        return;
    }

    foreach($language_root as $language)
    {
        if($language === '.' || $language === '..' || !is_dir(MYBB_ROOT.'inc/languages/'.$language))
        {
            continue;
        }

        delete_plugin_sweep_languages($ctx, 'inc/languages/'.$language);
    }
}

/**
 * Walks one language folder, one level of admin sub folders included.
 *
 * @param array $ctx Scan context
 * @param string $rel_dir Forum relative folder
 * @param int $depth Remaining depth
 * @return void
 */
function delete_plugin_sweep_languages(&$ctx, $rel_dir, $depth = 2)
{
    $entries = @scandir(MYBB_ROOT.$rel_dir);

    if($entries === false)
    {
        return;
    }

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..')
        {
            continue;
        }

        $path = $rel_dir.'/'.$entry;

        if(is_dir(MYBB_ROOT.$path))
        {
            if($depth > 0)
            {
                delete_plugin_sweep_languages($ctx, $path, $depth - 1);
            }

            continue;
        }

        delete_plugin_sweep_one($ctx, $path);
    }
}

/**
 * Decides on a single name matched file found by the sweep.
 *
 * @param array $ctx Scan context
 * @param string $rel_path Forum relative path
 * @return void
 */
function delete_plugin_sweep_one(&$ctx, $rel_path)
{
    if(!is_file(MYBB_ROOT.$rel_path) || isset($ctx['considered'][$rel_path]) || isset($ctx['accepted'][$rel_path]))
    {
        return;
    }

    if(!delete_plugin_name_matches(basename($rel_path), $ctx['codename']))
    {
        return;
    }

    if(!delete_plugin_content_related($rel_path, $ctx['codename'], $ctx['owned_folders']))
    {
        $ctx['considered'][$rel_path] = true;
        $ctx['skipped'][$rel_path] = 'not_related';
        return;
    }

    delete_plugin_consider_file($ctx, $rel_path, 'path');
}

/**
 * Looks for entry scripts that live in the forum root, such as sw.js or
 * store.php. A file only qualifies when it points at the plugin folder or when
 * its own name carries the plugin prefix and the content confirms it.
 *
 * @param array $ctx Scan context
 * @return void
 */
function delete_plugin_sweep_root(&$ctx)
{
    $entries = @scandir(MYBB_ROOT);

    if($entries === false)
    {
        return;
    }

    foreach($entries as $entry)
    {
        if($entry === '.' || $entry === '..' || is_dir(MYBB_ROOT.$entry))
        {
            continue;
        }

        $rel_path = $entry;

        if(isset($ctx['accepted'][$rel_path]))
        {
            continue;
        }

        if(!preg_match('/\.(php|js)$/i', $rel_path))
        {
            continue;
        }

        unset($ctx['considered'][$rel_path]);

        if(delete_plugin_match_patterns($rel_path, $ctx['excludes']) !== false || delete_plugin_is_core_path($rel_path))
        {
            continue;
        }

        $content = delete_plugin_read_text($rel_path);

        if($content === false)
        {
            continue;
        }

        $references_folder = false;

        foreach($ctx['owned_folders'] as $folder)
        {
            if(stripos($content, $folder) !== false)
            {
                $references_folder = true;
                break;
            }
        }

        $named = delete_plugin_name_matches($rel_path, $ctx['codename'])
            && delete_plugin_content_related($rel_path, $ctx['codename'], $ctx['owned_folders']);

        $strong = $references_folder || delete_plugin_namespace_related($rel_path, $ctx['codename']);

        if(!$strong && !$named)
        {
            continue;
        }

        $ctx['considered'][$rel_path] = true;
        delete_plugin_add_file($ctx, $rel_path, 'root_entry', true);
    }
}

/**
 * Adds the files a plugin registers by hand.
 *
 * Plugins that keep code outside the usual layout can expose
 * delete_plugin_extra_files_<codename>() and return forum relative paths.
 *
 * @param array $ctx Scan context
 * @param string $codename Plugin codename
 * @return void
 */
function delete_plugin_add_extra_files(&$ctx, $codename)
{
    $function = 'delete_plugin_extra_files_'.$codename;

    if(!function_exists($function))
    {
        return;
    }

    $extra = $function();

    if(!is_array($extra))
    {
        return;
    }

    foreach($extra as $rel_path)
    {
        $rel_path = delete_plugin_clean_path($rel_path);

        if($rel_path === '')
        {
            continue;
        }

        if(is_dir(MYBB_ROOT.$rel_path))
        {
            delete_plugin_walk($ctx, $rel_path, 'explicit');
            continue;
        }

        delete_plugin_add_file($ctx, $rel_path, 'explicit', true);
    }
}

/**
 * Adds the paths an administrator listed in the Extra Paths setting.
 *
 * @param array $ctx Scan context
 * @return void
 */
function delete_plugin_add_configured_paths(&$ctx)
{
    if(empty($ctx['includes']))
    {
        return;
    }

    foreach($ctx['includes'] as $pattern)
    {
        if(strpos($pattern, '*') !== false)
        {
            $matches = @glob(MYBB_ROOT.$pattern, GLOB_NOSORT);

            if($matches === false)
            {
                continue;
            }

            foreach($matches as $match)
            {
                $clean = delete_plugin_clean_path(substr($match, strlen(MYBB_ROOT)));

                if($clean !== '')
                {
                    delete_plugin_add_folder($ctx, $clean, 'path');
                }
            }

            continue;
        }

        $clean = delete_plugin_clean_path($pattern);

        if($clean === '')
        {
            continue;
        }

        if(is_dir(MYBB_ROOT.$clean))
        {
            delete_plugin_walk($ctx, $clean, 'explicit');
        }
        elseif(is_file(MYBB_ROOT.$clean))
        {
            delete_plugin_add_file($ctx, $clean, 'explicit', true);
        }
    }
}

/**
 * Adds inc/plugins/pluginlibrary.php when the plugin needs it and the setting
 * allows it.
 *
 * @param array $ctx Scan context
 * @return void
 */
function delete_plugin_apply_pluginlibrary(&$ctx)
{
    if(!delete_plugin_detect_pluginlibrary($ctx))
    {
        return;
    }

    $path = 'inc/plugins/pluginlibrary.php';

    if(!is_file(MYBB_ROOT.$path))
    {
        return;
    }

    if(isset($ctx['accepted'][$path]))
    {
        $ctx['pluginlibrary'] = true;
        return;
    }

    if(empty($ctx['settings']['include_pluginlibrary']))
    {
        $ctx['skipped'][$path] = 'setting';
        return;
    }

    if(delete_plugin_add_file($ctx, $path, 'pluginlibrary'))
    {
        $ctx['pluginlibrary'] = true;
    }
}

/**
 * Reads one literal metadata value out of a plugin _info() body.
 *
 * Handles both plain strings and constants, e.g. 'version' => PLUGIN_VERSION.
 *
 * @param string $body Source of the _info() function
 * @param string $key Metadata key
 * @param string $fallback Value used when the key is missing
 * @return string
 */
function delete_plugin_info_literal($body, $key, $fallback = '')
{
    $pattern = '/[\'"]'.preg_quote($key, '/').'[\'"]\s*=>\s*(?:([\'"])(.{1,120}?)\1|([A-Za-z_][A-Za-z0-9_]*))/s';

    if(!preg_match($pattern, $body, $match))
    {
        return $fallback;
    }

    if(isset($match[3]) && $match[3] !== '')
    {
        return defined($match[3]) ? (string)constant($match[3]) : $fallback;
    }

    return isset($match[2]) && $match[2] !== '' ? $match[2] : $fallback;
}

/**
 * Reads the plugin metadata through its _info() function when possible.
 *
 * @param string $codename Plugin codename
 * @return array name, version, codename
 */
function delete_plugin_get_plugin_info($codename)
{
    $info = array(
        'name'     => $codename,
        'version'  => '',
        'codename' => $codename,
    );

    $file = MYBB_ROOT.'inc/plugins/'.$codename.'.php';

    if(!is_file($file))
    {
        return $info;
    }

    $content = delete_plugin_read_text('inc/plugins/'.$codename.'.php', 131072);

    try
    {
        require_once $file;

        $function = $codename.'_info';

        if(function_exists($function))
        {
            $data = $function();

            if(is_array($data))
            {
                if(!empty($data['name']))
                {
                    $info['name'] = $data['name'];
                }

                if(!empty($data['version']))
                {
                    $info['version'] = $data['version'];
                }

                return $info;
            }
        }
    }
    catch (Throwable $e)
    {
        // The plugin could not run in this context, fall back to a static read.
    }

    if($content === false)
    {
        return $info;
    }

    $body = $content;

    if(preg_match('/function\s+'.preg_quote($codename.'_info', '/').'\s*\(.*?\n\}/s', $content, $match))
    {
        $body = $match[0];
    }

    $info['name'] = delete_plugin_info_literal($body, 'name', $info['name']);
    $info['version'] = delete_plugin_info_literal($body, 'version', '');

    return $info;
}

/**
 * Runs the full advanced scan for one plugin.
 *
 * @param string $codename Plugin codename
 * @param array $overrides Optional setting overrides for this run
 * @return array Scan result
 */
function delete_plugin_scan($codename, $overrides = array())
{
    $codename = delete_plugin_normalize_codename($codename);

    $result = array(
        'exists'             => false,
        'codename'           => $codename,
        'plugin_name'        => $codename,
        'plugin_version'     => '',
        'files'              => array(),
        'file_reasons'       => array(),
        'categories'         => array(),
        'sizes'              => array(),
        'skipped'            => array(),
        'skip_detail'        => array(),
        'folders'            => array(),
        'owned_folders'      => array(),
        'uses_pluginlibrary' => false,
        'pluginlibrary'      => false,
        'total_size'         => 0,
        'scan_mode'          => '',
        'settings'           => array(),
    );

    if($codename === '' || !is_file(MYBB_ROOT.'inc/plugins/'.$codename.'.php'))
    {
        return $result;
    }

    $settings = delete_plugin_get_settings();

    if(!empty($overrides) && is_array($overrides))
    {
        $settings = array_merge($settings, $overrides);

        // The path lists are derived values, they follow the overridden paths.
        $settings['excludes'] = delete_plugin_split_patterns($settings['excluded_paths']);
        $settings['includes'] = delete_plugin_split_patterns($settings['included_paths']);
    }

    $result['settings'] = $settings;
    $result['scan_mode'] = $settings['scan_mode'];

    $info = delete_plugin_get_plugin_info($codename);
    $result['plugin_name'] = $info['name'];
    $result['plugin_version'] = $info['version'];

    $max_size = (int)$settings['max_file_size'] * 1024;

    $ctx = array(
        'codename'           => $codename,
        'settings'           => $settings,
        'scan_mode'          => $settings['scan_mode'],
        'excludes'           => $settings['excludes'],
        'includes'           => $settings['includes'],
        'max_size'           => $max_size,
        'accepted'           => array(),
        'index'              => array(),
        'considered'         => array(),
        'skipped'            => array(),
        'skip_detail'        => array(),
        'queue'              => array(),
        'walked'             => array(),
        'roots'              => array(),
        'ref_cache'          => array(),
        'owned_folders'      => delete_plugin_find_owned_folders($codename),
        'uses_pluginlibrary' => false,
        'pluginlibrary'      => false,
        'total_size'         => 0,
    );

    foreach($ctx['owned_folders'] as $folder)
    {
        $ctx['roots'][$folder] = true;
    }

    $entry = 'inc/plugins/'.$codename.'.php';
    delete_plugin_add_file($ctx, $entry, 'entry', true);

    if(!isset($ctx['accepted'][$entry]))
    {
        return $result;
    }

    $result['exists'] = true;

    foreach($ctx['owned_folders'] as $folder)
    {
        delete_plugin_walk($ctx, $folder, 'owned');
    }

    delete_plugin_add_configured_paths($ctx);
    delete_plugin_add_extra_files($ctx, $codename);

    delete_plugin_drain_queue($ctx);

    if($ctx['scan_mode'] !== 'strict')
    {
        delete_plugin_sweep_named_files($ctx);
        delete_plugin_drain_queue($ctx);
    }

    if(!empty($settings['include_root_scripts']))
    {
        delete_plugin_sweep_root($ctx);
        delete_plugin_drain_queue($ctx);
    }

    delete_plugin_apply_pluginlibrary($ctx);

    $files = array_keys($ctx['accepted']);
    sort($files, SORT_STRING);

    foreach($files as $rel_path)
    {
        // The Delete Plugin folder is never part of another plugin, whatever
        // an odd codename makes the folder matcher believe.
        if(strpos($rel_path, 'inc/plugins/DeletePlugin/') === 0)
        {
            continue;
        }

        $entry_data = $ctx['accepted'][$rel_path];
        $result['files'][] = $rel_path;
        $result['file_reasons'][$rel_path] = $entry_data['reason'];
        $result['categories'][$rel_path] = $entry_data['category'];
        $result['sizes'][$rel_path] = $entry_data['size'];
    }

    $skipped = $ctx['skipped'];
    ksort($skipped);
    $result['skipped'] = $skipped;
    $result['skip_detail'] = $ctx['skip_detail'];

    $folders = array_keys($ctx['walked']);
    sort($folders, SORT_STRING);
    $result['folders'] = $folders;

    $result['owned_folders'] = $ctx['owned_folders'];
    $result['uses_pluginlibrary'] = $ctx['uses_pluginlibrary'];
    $result['pluginlibrary'] = $ctx['pluginlibrary'] || in_array('inc/plugins/pluginlibrary.php', $result['files'], true);
    $result['total_size'] = $ctx['total_size'];

    return $result;
}

/**
 * Parses every queued file until the queue is empty.
 *
 * @param array $ctx Scan context
 * @return void
 */
function delete_plugin_drain_queue(&$ctx)
{
    $guard = 0;

    while(!empty($ctx['queue']))
    {
        if(++$guard > 20000)
        {
            $ctx['queue'] = array();
            break;
        }

        $rel_path = array_shift($ctx['queue']);
        delete_plugin_process_file($ctx, $rel_path);
    }
}

/**
 * Counts the files per category.
 *
 * @param array $scan Scan result
 * @return array
 */
function delete_plugin_category_counts($scan)
{
    $counts = array(
        'plugin'     => 0,
        'language'   => 0,
        'asset'      => 0,
        'data'       => 0,
        'dependency' => 0,
    );

    foreach($scan['categories'] as $category)
    {
        if(!isset($counts[$category]))
        {
            $counts[$category] = 0;
        }

        $counts[$category]++;
    }

    return $counts;
}

/**
 * Backwards compatible helper: returns only the list of files.
 *
 * @param string $codename Plugin codename
 * @return array
 */
function delete_plugin_scan_plugin_files($codename)
{
    $scan = delete_plugin_scan($codename);

    return $scan['files'];
}

/**
 * Backwards compatible helper for the PluginLibrary check.
 *
 * @param string $codename Plugin codename
 * @param array $files Current file list, kept for compatibility
 * @return bool
 */
/**
 * True when source code touches PluginLibrary.
 *
 * Matches the PLUGINLIBRARY constant, the pluginlibrary.php path, the
 * PluginLibrary class name and its static calls.
 *
 * @param string $content Source code
 * @return bool
 */
function delete_plugin_content_uses_pluginlibrary($content)
{
    $patterns = array(
        '/\bPLUGINLIBRARY\b/',
        '/pluginlibrary\.php/i',
        '/new\s+PluginLibrary\b/i',
        '/\bPluginLibrary\s*::/',
    );

    foreach($patterns as $pattern)
    {
        if(preg_match($pattern, $content))
        {
            return true;
        }
    }

    return false;
}

/**
 * Looks for PluginLibrary usage in every scanned file.
 *
 * The plugin main file alone is not enough: plugins usually call PluginLibrary
 * from install.php or from their Functions folder.
 *
 * @param array $ctx Scan context
 * @return bool
 */
function delete_plugin_detect_pluginlibrary(&$ctx)
{
    if($ctx['uses_pluginlibrary'])
    {
        return true;
    }

    foreach(array_keys($ctx['accepted']) as $rel_path)
    {
        if($rel_path === 'inc/plugins/pluginlibrary.php')
        {
            $ctx['uses_pluginlibrary'] = true;

            return true;
        }

        $content = delete_plugin_read_text($rel_path, 131072);

        if($content !== false && delete_plugin_content_uses_pluginlibrary($content))
        {
            $ctx['uses_pluginlibrary'] = true;

            return true;
        }
    }

    return false;
}

function delete_plugin_check_pluginlibrary_dependency($codename, &$files)
{
    $scan = delete_plugin_scan($codename);

    if($scan['uses_pluginlibrary'])
    {
        return true;
    }

    $files = $scan['files'];
    return false;
}

/**
 * Formats a byte count for display.
 *
 * @param int $bytes Size in bytes
 * @return string
 */
function delete_plugin_format_size($bytes)
{
    $bytes = (int)$bytes;
    $units = array('B', 'KB', 'MB', 'GB');
    $index = 0;

    while($bytes >= 1024 && $index < count($units) - 1)
    {
        $bytes = $bytes / 1024;
        $index++;
    }

    return round($bytes, $index === 0 ? 0 : 1).' '.$units[$index];
}