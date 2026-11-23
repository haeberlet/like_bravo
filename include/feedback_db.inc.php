<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Data of the feedback for owners (the bell): the time of likes and bravos,
 * edited comments, and the clean-up of the plugin's tables when the core
 * deletes photos, comments or members. The feedback itself is read live
 * from the core tables rate and comments.
 */

/**
 * Notes the time of a vote after likeBravo.setVote stored it. A vote set
 * again unchanged keeps its time; a vote taken back loses its time and the
 * marks "read" of the owner.
 *
 * Rows are kept under the key of the rate row: photo, account and
 * identifier of the address. Members are found by photo and account alone,
 * because the core stores their identifier too and it changes with the
 * address.
 *
 * @param int $image_id
 * @param int $user_id
 * @param string $anonymous_id identifier stored with the new rate
 * @param bool $is_member false for guests and "generic" accounts
 * @param int|null $old_rate rate before the vote, null if none
 * @param int|null $new_rate rate after the vote, null if taken back
 */
function like_bravo_store_vote_time($image_id, $user_id, $anonymous_id, $is_member, $old_rate, $new_rate)
{
  $image_id = (int)$image_id;
  $user_id = (int)$user_id;
  $anonymous_id = pwg_db_real_escape_string((string)$anonymous_id);

  $where = 'image_id = '.$image_id.' AND user_id = '.$user_id;
  if (!$is_member)
  {
    $where.= ' AND anonymous_id = \''.$anonymous_id.'\'';
  }

  if (null === $new_rate)
  {
    pwg_query('DELETE FROM '.LIKE_BRAVO_VOTE_TIME_TABLE.' WHERE '.$where.';');

    $read_where = 'kind = \'vote\' AND ref_id = '.$image_id.' AND rater_id = '.$user_id;
    if (!$is_member)
    {
      $read_where.= ' AND rater_anonymous_id = \''.$anonymous_id.'\'';
    }
    pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE '.$read_where.';');
    return;
  }

  if (like_bravo_vote_changed($old_rate, $new_rate))
  {
    pwg_query('DELETE FROM '.LIKE_BRAVO_VOTE_TIME_TABLE.' WHERE '.$where.';');
    pwg_query('
INSERT INTO '.LIKE_BRAVO_VOTE_TIME_TABLE.'
  (image_id, user_id, anonymous_id, changed_at)
  VALUES ('.$image_id.', '.$user_id.', \''.$anonymous_id.'\', NOW())
;');
    return;
  }

  // unchanged: the time stays, the identifier follows the rate row
  if ($is_member)
  {
    pwg_query('
UPDATE '.LIKE_BRAVO_VOTE_TIME_TABLE.'
  SET anonymous_id = \''.$anonymous_id.'\'
  WHERE '.$where.'
;');
  }
}

/**
 * The stored rate of a vote, or null.
 *
 * @param int $image_id
 * @param int $user_id
 * @param string|null $anonymous_id only for guests and "generic" accounts
 * @return int|null
 */
function like_bravo_stored_rate($image_id, $user_id, $anonymous_id=null)
{
  $query = '
SELECT rate
  FROM '.RATE_TABLE.'
  WHERE element_id = '.(int)$image_id.'
    AND user_id = '.(int)$user_id;
  if (null !== $anonymous_id)
  {
    $query.= '
    AND anonymous_id = \''.pwg_db_real_escape_string($anonymous_id).'\'';
  }
  $query.= '
  LIMIT 1
;';
  $row = pwg_db_fetch_row(pwg_query($query));

  return $row ? (int)$row[0] : null;
}

/**
 * Event handler (user_comment_check): notes that a comment is being edited.
 * Returns the action unchanged. Whether the edit was saved is told by the
 * time of validation, see like_bravo_comment_is_edited().
 *
 * @param string $action 'validate', 'moderate' or 'reject'
 * @param array $comment
 * @return string
 */
function like_bravo_feedback_comment_check($action, $comment)
{
  $comment_id = like_bravo_edited_comment_id($action, $comment);

  if (null !== $comment_id)
  {
    pwg_query('
INSERT INTO '.LIKE_BRAVO_COMMENT_EDIT_TABLE.'
  (comment_id, edited_at)
  VALUES ('.$comment_id.', NOW())
  ON DUPLICATE KEY UPDATE edited_at = NOW()
;');
  }

  return $action;
}

/**
 * Event handler (user_comment_deletion).
 *
 * @param int|int[] $comment_ids
 */
function like_bravo_feedback_comment_deletion($comment_ids)
{
  $ids = array_filter(array_map('intval', (array)$comment_ids));
  if (empty($ids))
  {
    return;
  }
  $list = implode(',', $ids);

  pwg_query('DELETE FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' WHERE comment_id IN ('.$list.');');
  pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE kind = \'comment\' AND ref_id IN ('.$list.');');
}

/**
 * Event handler (delete_elements): the core has deleted the photos with
 * their votes and comments. The comment ids are gone by then, so rows of
 * comments that no longer exist are removed.
 *
 * @param int[] $image_ids
 */
function like_bravo_feedback_delete_elements($image_ids)
{
  $ids = array_filter(array_map('intval', (array)$image_ids));
  if (!empty($ids))
  {
    $list = implode(',', $ids);
    pwg_query('DELETE FROM '.LIKE_BRAVO_VOTE_TIME_TABLE.' WHERE image_id IN ('.$list.');');
    pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE kind = \'vote\' AND ref_id IN ('.$list.');');
  }

  pwg_query('
DELETE e
  FROM '.LIKE_BRAVO_COMMENT_EDIT_TABLE.' AS e
    LEFT JOIN '.COMMENTS_TABLE.' AS c ON c.id = e.comment_id
  WHERE c.id IS NULL
;');
  pwg_query('
DELETE r
  FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' AS r
    LEFT JOIN '.COMMENTS_TABLE.' AS c ON c.id = r.ref_id
  WHERE r.kind = \'comment\'
    AND c.id IS NULL
;');
}

/**
 * Event handler (delete_user): removes the read marks of the member. The
 * core keeps the votes and comments of a deleted member, and so does the
 * plugin keep their times: owners still see them, from a former member.
 *
 * @param int $user_id
 */
function like_bravo_feedback_delete_user($user_id)
{
  $user_id = (int)$user_id;

  pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_USER_TABLE.' WHERE user_id = '.$user_id.';');
  pwg_query('DELETE FROM '.LIKE_BRAVO_FEEDBACK_READ_TABLE.' WHERE user_id = '.$user_id.';');
}
