<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(dirname(__FILE__) . '/include/functions_logic.inc.php');

class club_likes_maintain extends PluginMaintain
{
  private $table;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);

    global $prefixeTable;
    $this->table = $prefixeTable . 'club_likes_modes';
  }

  /**
   * Creates the album mode table and stores the default configuration.
   * Safe to run repeatedly: existing modes and settings are kept, missing
   * settings are filled with their defaults.
   */
  function install($plugin_version, &$errors=array())
  {
    global $conf;

    // one row per album with an explicit mode; no row means "inherited"
    $query = '
CREATE TABLE IF NOT EXISTS `'.$this->table.'` (
  `cat_id` smallint(5) unsigned NOT NULL,
  `mode` tinyint(1) unsigned NOT NULL default 0,
  PRIMARY KEY (`cat_id`)
)
;';
    pwg_query($query);

    $stored = isset($conf['club_likes']) ? safe_unserialize($conf['club_likes']) : array();
    conf_update_param('club_likes', club_likes_normalize_conf($stored), true);
  }

  function update($old_version, $new_version, &$errors=array())
  {
    $this->install($new_version, $errors);
  }

  /**
   * Albums may still have modes from an earlier activation: brings the
   * scores of their photos back to the like / bravo formula.
   */
  function activate($plugin_version, &$errors=array())
  {
    global $conf;

    // the plugin is not loaded yet while it is being activated
    if (!defined('CLUB_LIKES_MODES_TABLE'))
    {
      define('CLUB_LIKES_MODES_TABLE', $this->table);
    }
    include_once(dirname(__FILE__) . '/include/functions_db.inc.php');
    include_once(dirname(__FILE__) . '/include/score.inc.php');

    $stored = isset($conf['club_likes']) ? $conf['club_likes'] : array();
    $conf['club_likes'] = club_likes_normalize_conf(is_array($stored) ? $stored : safe_unserialize($stored));

    club_likes_recompute_scores();
  }

  /**
   * Hands the scores back to Piwigo: every photo gets its core star score,
   * likes counting as 3 and bravos as 5.
   */
  function deactivate()
  {
    remove_event_handler('update_rating_score', 'club_likes_update_rating_score');

    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();
  }

  /**
   * Removes everything the plugin created. Votes stay in the native rate
   * table: they are regular Piwigo ratings (like = 3, bravo = 5).
   */
  function uninstall()
  {
    pwg_query('DROP TABLE IF EXISTS `'.$this->table.'`;');
    conf_delete_param('club_likes');
  }
}
