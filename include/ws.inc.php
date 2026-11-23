<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(LIKE_BRAVO_PATH . 'include/voters.inc.php');
include_once(LIKE_BRAVO_PATH . 'include/feedback_ws.inc.php');

/**
 * Event handler: registers the web service methods of the plugin.
 *
 * @param array $arr the service, by reference in the first element
 */
function like_bravo_ws_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'likeBravo.setVote',
    'like_bravo_ws_set_vote',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'level' => array('info' => 'none, like, bravo'),
      'pwg_token' => array(),
      ),
    'Sets or removes the vote of the current visitor on a photo.',
    null,
    array('post_only' => true)
    );

  $service->addMethod(
    'likeBravo.getVoters',
    'like_bravo_ws_get_voters',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      ),
    'Returns the names of the members who voted for a photo, if the plugin settings let the current visitor see them.'
    );

  like_bravo_feedback_ws_add_methods($service);
}

/**
 * Photo the current visitor may see, with the same access check as
 * pwg.images.rate.
 *
 * @param int $image_id
 * @return array|null 'id' and 'added_by', null if unknown or not accessible
 */
function like_bravo_ws_get_image($image_id)
{
  $query = '
SELECT DISTINCT id, added_by
  FROM '.IMAGES_TABLE.'
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id = image_id
  WHERE id = '.(int)$image_id
    .get_sql_condition_FandF(
      array(
        'forbidden_categories' => 'category_id',
        'forbidden_images' => 'id',
        ),
      '    AND'
      ).'
  LIMIT 1
;';
  $images = query2array($query);

  return empty($images) ? null : $images[0];
}

/**
 * API method
 * Sets or removes the vote of the current visitor on a photo. Anonymous
 * visitors need the plugin option and the Piwigo option "Rating by guests".
 * @param mixed[] $params
 *    @option int image_id
 *    @option string level 'none', 'like' or 'bravo'
 *    @option string pwg_token
 */
function like_bravo_ws_set_vote($params, &$service)
{
  global $conf, $user;

  // guests and "generic" accounts, as in rate_picture() of the core
  $anonymous = like_bravo_is_anonymous();

  if ($anonymous and !like_bravo_guests_take_part_now())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $image_id = (int)$params['image_id'];

  $image = like_bravo_ws_get_image($image_id);

  if (null === $image)
  {
    return new PwgError(404, 'Invalid image_id or access denied');
  }

  $mode = like_bravo_get_image_mode($image_id);

  if (!in_array($params['level'], like_bravo_allowed_levels($mode), true))
  {
    return new PwgError(403, 'This level is not available for this photo');
  }

  // an anonymous account is shared, none of its photos is "own"
  if (!$anonymous and !$conf['like_bravo']['allow_own_photos']
      and 'none' !== $params['level']
      and $image['added_by'] == $user['id'])
  {
    return new PwgError(403, 'You cannot rate your own photos');
  }

  if ($anonymous)
  {
    if (!like_bravo_set_anonymous_level($image_id, $params['level']))
    {
      return new PwgError(403, 'Rating is not possible');
    }
  }
  else
  {
    like_bravo_set_user_level($image_id, $user['id'], $params['level']);
  }

  $votes = like_bravo_get_image_votes($image_id, $mode);

  $result = array(
    'likes' => $votes['likes'],
    'bravos' => $votes['bravos'],
    'level' => like_bravo_get_user_level(
      $image_id, $user['id'], $mode,
      $anonymous ? like_bravo_current_anonymous_id() : null
      ),
    );

  if (like_bravo_names_visible())
  {
    $result['summaries'] = like_bravo_summary_texts(like_bravo_get_voters($image_id, $mode));
  }

  return $result;
}

/**
 * API method
 * Returns the names of the voters of a photo. Read only, so no token; who
 * may see names is a plugin setting.
 * @param mixed[] $params
 *    @option int image_id
 */
function like_bravo_ws_get_voters($params, &$service)
{
  if (!like_bravo_names_visible())
  {
    return new PwgError(401, 'Access denied');
  }

  $image_id = (int)$params['image_id'];

  if (null === like_bravo_ws_get_image($image_id))
  {
    return new PwgError(404, 'Invalid image_id or access denied');
  }

  $mode = like_bravo_get_image_mode($image_id);

  if (!like_bravo_is_thumbs_mode($mode))
  {
    return new PwgError(403, 'Names are not available for this photo');
  }

  return array(
    'lists' => like_bravo_voter_lists(like_bravo_get_voters($image_id, $mode)),
    );
}

/**
 * Event handler: refuses the native method pwg.images.rate on photos in a
 * thumbs mode or without rating. Star photos are not affected.
 *
 * @param mixed $result
 * @param string $method_name
 * @param array $params
 * @return mixed
 */
function like_bravo_ws_invoke_allowed($result, $method_name, $params)
{
  if ('pwg.images.rate' == $method_name
      and isset($params['image_id'])
      and is_numeric($params['image_id'])
      and LIKE_BRAVO_MODE_STARS != like_bravo_get_image_mode($params['image_id']))
  {
    return new PwgError(403, 'Rating with stars is not available for this photo');
  }

  return $result;
}
