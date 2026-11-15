<?php
/*
Plugin Name: Club Likes
Version: 0.1.0
Description: Replaces the star rating by "Like" / "Bravo" in selected albums, built on the native rating system.
Plugin URI:
Author: Tilmann Haeberle
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

global $prefixeTable;

define('CLUB_LIKES_ID', basename(dirname(__FILE__)));
define('CLUB_LIKES_PATH', PHPWG_PLUGINS_PATH . CLUB_LIKES_ID . '/');
define('CLUB_LIKES_MODES_TABLE', $prefixeTable . 'club_likes_modes');
define('CLUB_LIKES_ADMIN', get_root_url() . 'admin.php?page=plugin-' . CLUB_LIKES_ID);

include_once(CLUB_LIKES_PATH . 'include/functions_logic.inc.php');
include_once(CLUB_LIKES_PATH . 'include/functions_db.inc.php');

add_event_handler('init', 'club_likes_init');
add_event_handler('delete_categories', 'club_likes_delete_categories');

// scores: the plugin replaces the core computation
add_event_handler('update_rating_score', 'club_likes_update_rating_score',
  EVENT_HANDLER_PRIORITY_NEUTRAL, CLUB_LIKES_PATH . 'include/score.inc.php');

// web service: own vote method, native rating refused on thumbs photos
add_event_handler('ws_add_methods', 'club_likes_ws_add_methods',
  EVENT_HANDLER_PRIORITY_NEUTRAL, CLUB_LIKES_PATH . 'include/ws.inc.php');
add_event_handler('ws_invoke_allowed', 'club_likes_ws_invoke_allowed',
  EVENT_HANDLER_PRIORITY_NEUTRAL + 10, CLUB_LIKES_PATH . 'include/ws.inc.php');

// picture page: no native star rating on thumbs photos, own widget instead
add_event_handler('loc_begin_picture', 'club_likes_block_native_rate',
  EVENT_HANDLER_PRIORITY_NEUTRAL, CLUB_LIKES_PATH . 'include/picture.inc.php');
add_event_handler('loc_end_picture', 'club_likes_picture',
  EVENT_HANDLER_PRIORITY_NEUTRAL, CLUB_LIKES_PATH . 'include/picture.inc.php');

if (defined('IN_ADMIN'))
{
  add_event_handler('tabsheet_before_select', 'club_likes_tabsheet_before_select');
}

/**
 * Loads the plugin configuration and language files.
 */
function club_likes_init()
{
  global $conf;

  $stored = isset($conf['club_likes']) ? safe_unserialize($conf['club_likes']) : array();
  $conf['club_likes'] = club_likes_normalize_conf($stored);

  load_language('plugin.lang', CLUB_LIKES_PATH);
}

/**
 * Adds the "Member rating" tab to the album edit page.
 *
 * @param array $sheets
 * @param string $id id of the tabsheet being built
 * @return array
 */
function club_likes_tabsheet_before_select($sheets, $id)
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

    $sheets['club_likes'] = array(
      'caption' => '<span class="icon-thumbs-up"></span>'.l10n('Member rating'),
      'url' => CLUB_LIKES_ADMIN.'-album&amp;cat_id='.(int)$_GET['cat_id'],
      );
  }

  return $sheets;
}
