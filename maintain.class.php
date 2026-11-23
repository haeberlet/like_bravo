<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(dirname(__FILE__) . '/include/functions_logic.inc.php');

class like_bravo_maintain extends PluginMaintain
{
  private $table;
  private $feedback_tables;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);

    global $prefixeTable;
    $this->table = $prefixeTable . 'like_bravo_modes';
    $this->feedback_tables = array(
      'vote_time' => $prefixeTable . 'like_bravo_vote_time',
      'feedback_user' => $prefixeTable . 'like_bravo_feedback_user',
      'feedback_read' => $prefixeTable . 'like_bravo_feedback_read',
      'comment_edit' => $prefixeTable . 'like_bravo_comment_edit',
      );
  }

  /**
   * Creates the plugin tables and stores the default configuration.
   * Safe to run repeatedly: existing modes, settings and feedback data are
   * kept, missing settings are filled with their defaults. Piwigo also runs
   * it through update() when the version in main.inc.php rises.
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

    $this->create_feedback_tables();

    $stored = isset($conf['like_bravo']) ? safe_unserialize($conf['like_bravo']) : array();
    conf_update_param('like_bravo', like_bravo_normalize_conf($stored), true);

    // Feedback starts now: what members received before counts as read,
    // also for members without a row of their own (see KONZEPT-RUECKMELDUNGEN
    // 12.2). Kept on later updates.
    if (empty($conf['like_bravo_feedback_since']))
    {
      list($now) = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
      conf_update_param('like_bravo_feedback_since', $now, true);
    }
  }

  /**
   * Tables for the feedback of the owners (the bell). The identifier of
   * anonymous voters gets the character set and collation of the core
   * column it is joined with, so that MySQL and MariaDB compare them
   * without an error, whatever the defaults of the server are.
   */
  private function create_feedback_tables()
  {
    $column = pwg_db_fetch_assoc(pwg_query('SHOW FULL COLUMNS FROM `'.RATE_TABLE.'` LIKE \'anonymous_id\';'));
    $anonymous_id = 'varchar(45)';
    if (!empty($column['Collation']) and preg_match('/^([a-z0-9]+)_[a-z0-9_]+$/', $column['Collation'], $matches))
    {
      $anonymous_id.= ' CHARACTER SET '.$matches[1].' COLLATE '.$column['Collation'];
    }

    // time of day of likes and bravos: rate.date keeps only the day
    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->feedback_tables['vote_time'].'` (
  `image_id` mediumint(8) unsigned NOT NULL,
  `user_id` mediumint(8) unsigned NOT NULL,
  `anonymous_id` '.$anonymous_id.' NOT NULL default \'\',
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`image_id`, `user_id`, `anonymous_id`)
)
;');

    // "mark all as read" of one member
    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->feedback_tables['feedback_user'].'` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `read_all_before` datetime NOT NULL,
  PRIMARY KEY (`user_id`)
)
;');

    // entries read one by one: a vote (photo + voter) or a comment
    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->feedback_tables['feedback_read'].'` (
  `user_id` mediumint(8) unsigned NOT NULL,
  `kind` enum(\'vote\',\'comment\') NOT NULL,
  `ref_id` int(11) unsigned NOT NULL,
  `rater_id` mediumint(8) unsigned NOT NULL default 0,
  `rater_anonymous_id` '.$anonymous_id.' NOT NULL default \'\',
  `read_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`, `kind`, `ref_id`, `rater_id`, `rater_anonymous_id`)
)
;');

    // last edit of a comment, noted before the core saves it
    pwg_query('
CREATE TABLE IF NOT EXISTS `'.$this->feedback_tables['comment_edit'].'` (
  `comment_id` int(11) unsigned NOT NULL,
  `edited_at` datetime NOT NULL,
  PRIMARY KEY (`comment_id`)
)
;');
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
    if (!defined('LIKE_BRAVO_MODES_TABLE'))
    {
      define('LIKE_BRAVO_MODES_TABLE', $this->table);
    }
    include_once(dirname(__FILE__) . '/include/functions_db.inc.php');
    include_once(dirname(__FILE__) . '/include/score.inc.php');

    $stored = isset($conf['like_bravo']) ? $conf['like_bravo'] : array();
    $conf['like_bravo'] = like_bravo_normalize_conf(is_array($stored) ? $stored : safe_unserialize($stored));

    like_bravo_recompute_scores();
  }

  /**
   * Hands the scores back to Piwigo: every photo gets its core star score,
   * likes counting as 2 and bravos as 4.
   */
  function deactivate()
  {
    remove_event_handler('update_rating_score', 'like_bravo_update_rating_score');

    include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
    update_rating_score();
  }

  /**
   * Removes everything the plugin created. Votes stay in the native rate
   * table: they are regular Piwigo ratings (like = 2, bravo = 4).
   */
  function uninstall()
  {
    pwg_query('DROP TABLE IF EXISTS `'.$this->table.'`;');
    foreach ($this->feedback_tables as $table)
    {
      pwg_query('DROP TABLE IF EXISTS `'.$table.'`;');
    }
    conf_delete_param(array('like_bravo', 'like_bravo_feedback_since'));
  }
}
