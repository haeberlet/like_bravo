<?php
/*
Plugin Name: Like & Bravo
Version: 0.1.0
Description: Replaces the star rating by "Like" / "Bravo" in selected albums, built on the native rating system.
Plugin URI:
Author: Tilmann Haeberle
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

global $prefixeTable;

define('LIKE_BRAVO_ID', basename(dirname(__FILE__)));
define('LIKE_BRAVO_PATH', PHPWG_PLUGINS_PATH . LIKE_BRAVO_ID . '/');
define('LIKE_BRAVO_MODES_TABLE', $prefixeTable . 'like_bravo_modes');
define('LIKE_BRAVO_ADMIN', get_root_url() . 'admin.php?page=plugin-' . LIKE_BRAVO_ID);

include_once(LIKE_BRAVO_PATH . 'include/functions_logic.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/functions_db.inc.php');

add_event_handler('init', 'like_bravo_init');
add_event_handler('delete_categories', 'like_bravo_delete_categories');

// scores: the plugin replaces the core computation
add_event_handler('update_rating_score', 'like_bravo_update_rating_score',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/score.inc.php');

// web service: own vote method, native rating refused on thumbs photos
add_event_handler('ws_add_methods', 'like_bravo_ws_add_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/ws.inc.php');
add_event_handler('ws_invoke_allowed', 'like_bravo_ws_invoke_allowed',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, LIKE_BRAVO_PATH . 'include/ws.inc.php');

// picture page: no native star rating on thumbs photos, own widget instead
add_event_handler('loc_begin_picture', 'like_bravo_block_native_rate',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/picture.inc.php');
add_event_handler('loc_end_picture', 'like_bravo_picture',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/picture.inc.php');

// album pages: likes and bravos below the thumbnails
add_event_handler('loc_end_index_thumbnails', 'like_bravo_index_thumbnails',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/thumbnails.inc.php');

// personal pages "My likes" and "My bravos" in the menu block "Explore"
add_event_handler('blockmanager_apply', 'like_bravo_blockmanager_apply',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/sections.inc.php');
add_event_handler('loc_end_section_init', 'like_bravo_section_init',
  EVENT_HANDLER_PRIORITY_NEUTRAL, LIKE_BRAVO_PATH . 'include/sections.inc.php');

if (defined('IN_ADMIN'))
{
  add_event_handler('tabsheet_before_select', 'like_bravo_tabsheet_before_select');
}

/**
 * Loads the plugin configuration and language files.
 */
function like_bravo_init()
{
  global $conf;

  $stored = isset($conf['like_bravo']) ? safe_unserialize($conf['like_bravo']) : array();
  $conf['like_bravo'] = like_bravo_normalize_conf($stored);

  load_language('plugin.lang', LIKE_BRAVO_PATH);

  // The core reads local/language/<code>.lang.php before this event, so the
  // plugin texts would overwrite it. Reading it again lets a webmaster change
  // any text of the plugin per language (LocalFilesEditor).
  load_language('lang', PHPWG_ROOT_PATH.PWG_LOCAL_DIR, array('no_fallback' => true, 'local' => true));
}

/**
 * Adds the "Member rating" tab to the album edit page.
 *
 * @param array $sheets
 * @param string $id id of the tabsheet being built
 * @return array
 */
function like_bravo_tabsheet_before_select($sheets, $id)
{
  if ('album' == $id and isset($_GET['cat_id']))
  {
    global $template;
    static $style_added = false;

    // The core tabs float and grow by 10px on hover. Once the tab bar wraps,
    // a tab on the second line slides under whichever tab is hovered. Giving
    // every tab the same outer height keeps the second line in place.
    if (!$style_added)
    {
      $template->block_html_style(null,
        '.tabsheet li.normal_tab { margin-bottom:10px; }'
        .' .tabsheet li.normal_tab:hover { margin-bottom:0; }'
        );
      $style_added = true;
    }

    $sheets['like_bravo'] = array(
      'caption' => '<span class="icon-thumbs-up"></span>'.l10n('Member rating'),
      'url' => LIKE_BRAVO_ADMIN.'-album&amp;cat_id='.(int)$_GET['cat_id'],
      );
  }

  return $sheets;
}
