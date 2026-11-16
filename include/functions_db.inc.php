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
function like_bravo_get_explicit_modes($refresh=false)
{
  static $modes = null;

  if (null === $modes or $refresh)
  {
    $query = '
SELECT cat_id, mode
  FROM '.LIKE_BRAVO_MODES_TABLE.'
;';
    $modes = array_map('intval', query2array($query, 'cat_id', 'mode'));
  }

  return $modes;
}

/**
 * Effective mode of an album, with the album it is inherited from.
 *
 * @param int $cat_id
 * @return array|null as like_bravo_resolve_mode(), null for an unknown album
 */
function like_bravo_get_category_mode($cat_id)
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

  return like_bravo_resolve_mode($rows[0], like_bravo_get_explicit_modes());
}

/**
 * Mode of a photo from the effective modes of all albums it belongs to
 * (rule in like_bravo_mode_rank()), whatever album or page it is displayed
 * from.
 *
 * @param int $image_id
 * @return int
 */
function like_bravo_get_image_mode($image_id)
{
  $explicit_modes = like_bravo_get_explicit_modes();

  if (empty($explicit_modes))
  {
    return LIKE_BRAVO_MODE_STARS;
  }

  $query = '
SELECT c.uppercats
  FROM '.IMAGE_CATEGORY_TABLE.' AS ic
    INNER JOIN '.CATEGORIES_TABLE.' AS c ON c.id = ic.category_id
  WHERE ic.image_id = '.(int)$image_id.'
;';

  $resolved_modes = array();
  foreach (query2array($query, null, 'uppercats') as $uppercats)
  {
    $resolved_modes[] = like_bravo_resolve_mode($uppercats, $explicit_modes);
  }

  return like_bravo_combine_modes($resolved_modes);
}

/**
 * Mode an album would inherit, i.e. its effective mode if it had no
 * explicit mode of its own.
 *
 * @param string $uppercats categories.uppercats of the album
 * @return array as like_bravo_resolve_mode()
 */
function like_bravo_get_inherited_mode($uppercats)
{
  $ids = explode(',', $uppercats);
  array_pop($ids); // the album itself

  return like_bravo_resolve_mode(implode(',', $ids), like_bravo_get_explicit_modes());
}

/**
 * Stores or removes the explicit mode of an album.
 *
 * @param int $cat_id
 * @param int|null $mode null = inherited (no explicit mode)
 */
function like_bravo_set_category_mode($cat_id, $mode)
{
  $cat_id = (int)$cat_id;

  if (null === $mode)
  {
    $query = '
DELETE FROM '.LIKE_BRAVO_MODES_TABLE.'
  WHERE cat_id = '.$cat_id.'
;';
  }
  else
  {
    $mode = (int)$mode;
    $query = '
INSERT INTO '.LIKE_BRAVO_MODES_TABLE.' (cat_id, mode)
  VALUES ('.$cat_id.', '.$mode.')
  ON DUPLICATE KEY UPDATE mode = '.$mode.'
;';
  }
  pwg_query($query);

  like_bravo_get_explicit_modes(true);
}

/**
 * Event handler: forgets the modes of deleted albums. Photos that stay in
 * the gallery may now be in another mode, so the scores are recomputed.
 *
 * @param int[] $cat_ids
 */
function like_bravo_delete_categories($cat_ids)
{
  $cat_ids = array_map('intval', (array)$cat_ids);

  if (empty($cat_ids) or 0 == count(like_bravo_get_explicit_modes()))
  {
    return;
  }

  $query = '
DELETE FROM '.LIKE_BRAVO_MODES_TABLE.'
  WHERE cat_id IN ('.implode(',', $cat_ids).')
;';
  pwg_query($query);

  like_bravo_get_explicit_modes(true);

  include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
  update_rating_score();
}
