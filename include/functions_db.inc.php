<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Database access for album and photo modes. The rules themselves live in
 * functions_logic.inc.php.
 */

/**
 * @param bool $refresh re-read from the database (after a mode was changed)
 * @return array cat_id => mode for all albums with an explicit mode
 */
function club_likes_get_explicit_modes($refresh=false)
{
  static $modes = null;

  if (null === $modes or $refresh)
  {
    $query = '
SELECT cat_id, mode
  FROM '.CLUB_LIKES_MODES_TABLE.'
;';
    $modes = array_map('intval', query2array($query, 'cat_id', 'mode'));
  }

  return $modes;
}

/**
 * Effective mode of an album, with the album it is inherited from.
 *
 * @param int $cat_id
 * @return array|null as club_likes_resolve_mode(), null for an unknown album
 */
function club_likes_get_category_mode($cat_id)
{
  $query = '
SELECT uppercats
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int)$cat_id.'
;';
  $rows = query2array($query, null, 'uppercats');

  if (empty($rows))
  {
    return null;
  }

  return club_likes_resolve_mode($rows[0], club_likes_get_explicit_modes());
}

/**
 * Mode of a photo: highest effective mode of all albums it belongs to,
 * whatever album or page it is displayed from.
 *
 * @param int $image_id
 * @return int
 */
function club_likes_get_image_mode($image_id)
{
  $explicit_modes = club_likes_get_explicit_modes();

  if (empty($explicit_modes))
  {
    return CLUB_LIKES_MODE_INACTIVE;
  }

  $query = '
SELECT c.uppercats
  FROM '.IMAGE_CATEGORY_TABLE.' AS ic
    INNER JOIN '.CATEGORIES_TABLE.' AS c ON c.id = ic.category_id
  WHERE ic.image_id = '.(int)$image_id.'
;';

  $modes = array();
  foreach (query2array($query, null, 'uppercats') as $uppercats)
  {
    $resolved = club_likes_resolve_mode($uppercats, $explicit_modes);
    $modes[] = $resolved['mode'];
  }

  return club_likes_combine_modes($modes);
}

/**
 * Mode an album would inherit, i.e. its effective mode if it had no
 * explicit mode of its own.
 *
 * @param string $uppercats categories.uppercats of the album
 * @return array as club_likes_resolve_mode()
 */
function club_likes_get_inherited_mode($uppercats)
{
  $ids = explode(',', $uppercats);
  array_pop($ids); // the album itself

  return club_likes_resolve_mode(implode(',', $ids), club_likes_get_explicit_modes());
}

/**
 * Stores or removes the explicit mode of an album.
 *
 * @param int $cat_id
 * @param int|null $mode null = inherited (no explicit mode)
 */
function club_likes_set_category_mode($cat_id, $mode)
{
  $cat_id = (int)$cat_id;

  if (null === $mode)
  {
    $query = '
DELETE FROM '.CLUB_LIKES_MODES_TABLE.'
  WHERE cat_id = '.$cat_id.'
;';
  }
  else
  {
    $mode = (int)$mode;
    $query = '
INSERT INTO '.CLUB_LIKES_MODES_TABLE.' (cat_id, mode)
  VALUES ('.$cat_id.', '.$mode.')
  ON DUPLICATE KEY UPDATE mode = '.$mode.'
;';
  }
  pwg_query($query);

  club_likes_get_explicit_modes(true);
}

/**
 * Event handler: forgets the modes of deleted albums. Photos that stay in
 * the gallery may now be in another mode, so the scores are recomputed.
 *
 * @param int[] $cat_ids
 */
function club_likes_delete_categories($cat_ids)
{
  $cat_ids = array_map('intval', (array)$cat_ids);

  if (empty($cat_ids) or 0 == count(club_likes_get_explicit_modes()))
  {
    return;
  }

  $query = '
DELETE FROM '.CLUB_LIKES_MODES_TABLE.'
  WHERE cat_id IN ('.implode(',', $cat_ids).')
;';
  pwg_query($query);

  club_likes_get_explicit_modes(true);

  include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
  update_rating_score();
}
