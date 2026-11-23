<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/feedback.inc.php');

/**
 * Event handler (loc_end_page_header): the bell of the feedback for owners
 * on every page of the gallery, for signed-in members. The page gets the
 * number of unread entries only; the list is loaded by js/bell.js when the
 * bell is opened. The script puts the bell in a known place of the theme,
 * or in the top right corner of the window.
 */
function like_bravo_bell()
{
  global $template, $user;

  if (defined('IN_ADMIN') or like_bravo_is_anonymous())
  {
    return;
  }

  $config = array(
    'url' => get_root_url().'ws.php?format=json',
    'token' => get_pwg_token(),
    'count' => like_bravo_feedback_badge_count($user['id']),
    'theme' => isset($user['theme']) ? (string)$user['theme'] : '',
    'strings' => array(
      'title' => l10n('Feedback'),
      'unread_count' => l10n('Feedback: %d unread'),
      'mark_all' => l10n('Mark all as read'),
      'mark_one' => l10n('Mark as read'),
      'unread' => l10n('unread'),
      'empty' => l10n('No feedback on your photos yet.'),
      'error' => l10n('The feedback could not be loaded.'),
      'close' => l10n('Close'),
      ),
    );

  $template->func_combine_css(array('path' => 'plugins/'.LIKE_BRAVO_ID.'/css/bell.css'));
  $template->func_combine_script(array(
    'id' => 'like_bravo_bell',
    'load' => 'footer',
    'path' => 'plugins/'.LIKE_BRAVO_ID.'/js/bell.js',
    ));
  $template->block_footer_script(
    array('require' => 'like_bravo_bell'),
    'LikeBravoBell.start('.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');'
    );
}
