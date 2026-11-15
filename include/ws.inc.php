<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

include_once(CLUB_LIKES_PATH . 'include/score.inc.php');

/**
 * Event handler: registers the web service methods of the plugin.
 *
 * @param array $arr the service, by reference in the first element
 */
function club_likes_ws_add_methods($arr)
{
  $service = &$arr[0];

  $service->addMethod(
    'clubLikes.setVote',
    'club_likes_ws_set_vote',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'level' => array('info' => 'none, like, bravo'),
      'pwg_token' => array(),
      ),
    'Sets or removes the vote of the connected member on a photo.',
    null,
    array('post_only' => true)
    );
}

/**
 * API method
 * Sets or removes the vote of the connected member on a photo
 * @param mixed[] $params
 *    @option int image_id
 *    @option string level 'none', 'like' or 'bravo'
 *    @option string pwg_token
 */
function club_likes_ws_set_vote($params, &$service)
{
  global $conf, $user;

  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $image_id = (int)$params['image_id'];

  // same access check as pwg.images.rate
  $query = '
SELECT DISTINCT id, added_by
  FROM '.IMAGES_TABLE.'
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' ON id = image_id
  WHERE id = '.$image_id
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

  if (empty($images))
  {
    return new PwgError(404, 'Invalid image_id or access denied');
  }

  $mode = club_likes_get_image_mode($image_id);

  if (!in_array($params['level'], club_likes_allowed_levels($mode), true))
  {
    return new PwgError(403, 'This level is not available for this photo');
  }

  if (!$conf['club_likes']['allow_own_photos']
      and 'none' !== $params['level']
      and $images[0]['added_by'] == $user['id'])
  {
    return new PwgError(403, 'You cannot rate your own photos');
  }

  club_likes_set_user_level($image_id, $user['id'], $params['level']);

  $votes = club_likes_get_image_votes($image_id, $mode);

  return array(
    'likes' => $votes['likes'],
    'bravos' => $votes['bravos'],
    'level' => club_likes_get_user_level($image_id, $user['id'], $mode),
    );
}

/**
 * Event handler: refuses the native method pwg.images.rate on photos in a
 * thumbs mode. Star photos are not affected.
 *
 * @param mixed $result
 * @param string $method_name
 * @param array $params
 * @return mixed
 */
function club_likes_ws_invoke_allowed($result, $method_name, $params)
{
  if ('pwg.images.rate' == $method_name
      and isset($params['image_id'])
      and is_numeric($params['image_id'])
      and CLUB_LIKES_MODE_INACTIVE != club_likes_get_image_mode($params['image_id']))
  {
    return new PwgError(403, 'Star rating is disabled for this photo');
  }

  return $result;
}
