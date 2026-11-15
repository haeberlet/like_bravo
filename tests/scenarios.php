<?php
/**
 * Scenario tests against a running Piwigo: switching albums between stars
 * and thumbs, photos in several albums, album deletion, deactivation,
 * uninstall and reinstall.
 *
 * ONLY FOR A TEST GALLERY. The run replaces all ratings and album modes while
 * it works and uninstalls the plugin once. Existing ratings, modes and
 * settings are saved first and put back at the end, also if the run aborts.
 * Needs at least three photos in the gallery.
 *
 *   php tests/scenarios.php      (inside the Piwigo container)
 */

if (PHP_SAPI !== 'cli')
{
  die('Hacking attempt!');
}

chdir(dirname(__DIR__, 3));
define('PHPWG_ROOT_PATH','./'); define('IN_ADMIN', true);
$_SERVER['REMOTE_ADDR']='192.168.7.33'; $_SERVER['HTTP_HOST']='localhost'; $_SERVER['REQUEST_URI']='/admin.php'; $_SERVER['SCRIPT_NAME']='/admin.php';
include_once('./include/common.inc.php');
include_once('./admin/include/functions.php');
include_once('./admin/include/plugins.class.php');
include_once('./include/functions_rate.inc.php');
include_once(LIKE_BRAVO_PATH.'include/score.inc.php');
error_reporting(E_ALL); ini_set('display_errors', 1);
$user = build_user($conf['webmaster_id'], true);

$photos = query2array('SELECT id FROM '.IMAGES_TABLE.' ORDER BY id LIMIT 3', null, 'id');
if (count($photos) < 3)
{
  die("at least three photos are needed\n");
}
list($a, $b, $c) = array_map('intval', $photos);

$fail = 0;
function ok($label, $expected, $actual) { global $fail; $good = $expected == $actual; if (!$good) $fail++; echo ($good ? 'PASS ' : 'FAIL ').$label.($good ? '' : '  expected '.json_encode($expected).' got '.json_encode($actual))."\n"; }
function scores() { $s = query2array('SELECT id, rating_score FROM '.IMAGES_TABLE.' WHERE rating_score IS NOT NULL', 'id', 'rating_score'); ksort($s); return array_map('floatval', $s); }
function rates_sig() { return md5(json_encode(query2array('SELECT user_id, element_id, rate FROM '.RATE_TABLE.' ORDER BY element_id, user_id'))); }
function core_scores() { // independent re-implementation of the core formula over all rates
  $by = array(); foreach (query2array('SELECT element_id e, rate r FROM '.RATE_TABLE) as $x) $by[$x['e']][] = (int)$x['r'];
  if (!$by) return array(); $n = 0; $s = 0; foreach ($by as $r) { $n += count($r); $s += array_sum($r); } $m = $s/$n; $c = $n/count($by);
  $out = array(); foreach ($by as $id => $r) $out[$id] = round(($c*$m + array_sum($r))/($c + count($r)), 2); ksort($out); return $out; }
function vote($img, $uid, $rate) { single_insert(RATE_TABLE, array('user_id'=>$uid,'anonymous_id'=>'10.0.0','element_id'=>$img,'rate'=>$rate,'date'=>'2026-11-15')); }
function mode($cat, $m) { like_bravo_set_category_mode($cat, $m); update_rating_score(); }

// ---- snapshot of whatever is in the test database ----
$snap = array(
  'rates' => query2array('SELECT * FROM '.RATE_TABLE),
  'modes' => query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE),
  'conf' => $conf['like_bravo'],
  );
echo "snapshot: ".count($snap['rates'])." rates, ".count($snap['modes'])." modes\n";
register_shutdown_function('restore_snapshot');
pwg_query('DELETE FROM '.RATE_TABLE); pwg_query('DELETE FROM '.LIKE_BRAVO_MODES_TABLE); like_bravo_get_explicit_modes(true);
conf_update_param('like_bravo', like_bravo_default_conf(), true);

$t1 = create_virtual_category('cl_test_T1')['id'];
$t2 = create_virtual_category('cl_test_T2', $t1)['id'];
$t3 = create_virtual_category('cl_test_T3')['id'];
mass_inserts(IMAGE_CATEGORY_TABLE, array('image_id','category_id'), array(
  array('image_id'=>$a,'category_id'=>$t2), array('image_id'=>$b,'category_id'=>$t2),
  array('image_id'=>$b,'category_id'=>$t3), array('image_id'=>$c,'category_id'=>$t3)));
echo "albums T1=$t1 > T2=$t2, T3=$t3; photo A in T2, B in T2+T3, C in T3\n\n";

echo "== 1. star ratings, no album in a thumbs mode\n";
foreach (array(9001=>5, 9002=>4, 9003=>3, 9004=>1, 9005=>0) as $u=>$r) vote($a, $u, $r);
vote($b, 9001, 3); vote($c, 9001, 5); vote($c, 9002, 2);
update_rating_score(); $stars = scores(); $sig = rates_sig();
ok('plugin star scores equal the core formula', core_scores(), $stars);

echo "== 2. stars -> thumbs: T1 becomes like + bravo (T2 inherits)\n";
mode($t1, LIKE_BRAVO_MODE_BOTH);
ok('photo A: mode like + bravo through inheritance', 2, like_bravo_get_image_mode($a));
ok('photo A: 3 = like, 4-5 = bravo, 0-2 dormant', array('likes'=>1,'bravos'=>2,'dormant'=>2), like_bravo_get_image_votes($a, 2));
$s = scores();
ok('photo A: score 5*5/(5+5)', 2.5, $s[$a]);
ok('photo B: one like', 0.83, $s[$b]);
ok('photo C stays a star photo, mean from star photos only', 3.5, $s[$c]);
ok('stored ratings untouched', $sig, rates_sig());

echo "== 3. photo in several albums: highest mode wins\n";
mode($t3, LIKE_BRAVO_MODE_LIKE);
ok('photo B in like + bravo (T2) and like (T3): like + bravo', 2, like_bravo_get_image_mode($b));
ok('photo C only in T3: like', 1, like_bravo_get_image_mode($c));
$s = scores();
ok('photo C: 5 counts as like, 2 is dormant', 0.83, $s[$c]);
mode($t2, LIKE_BRAVO_MODE_INACTIVE);
ok('T2 explicitly inactive stops inheritance: photo A is a star photo', 0, like_bravo_get_image_mode($a));
ok('photo B now inactive (T2) and like (T3): like', 1, like_bravo_get_image_mode($b));
ok('photo B: bravo is not offered', array('none','like'), like_bravo_allowed_levels(like_bravo_get_image_mode($b)));
$s = scores();
ok('photo A back to its star score, alone among star photos', core_scores_of(array($a)), $s[$a]);
mode($t2, null);
ok('T2 back to inherited: photo A like + bravo again', 2, like_bravo_get_image_mode($a));

echo "== 4. member votes, then thumbs -> stars\n";
like_bravo_set_user_level($b, 9010, 'bravo'); like_bravo_set_user_level($a, 9010, 'like'); like_bravo_set_user_level($a, 9011, 'like');
like_bravo_set_user_level($a, 9011, 'none');
ok('bravo stored as 5, like as 3, removed vote leaves no row',
  array(array('user_id'=>'9010','element_id'=>(string)$a,'rate'=>'3'), array('user_id'=>'9010','element_id'=>(string)$b,'rate'=>'5')),
  query2array('SELECT user_id, element_id, rate FROM '.RATE_TABLE.' WHERE user_id IN (9010, 9011) ORDER BY element_id'));
$sig = rates_sig(); $thumbs = scores();
mode($t1, null); mode($t3, null);
ok('all albums back to stars: scores are the core scores of all ratings', core_scores(), scores());
ok('stored ratings untouched', $sig, rates_sig());
mode($t1, LIKE_BRAVO_MODE_BOTH); mode($t3, LIKE_BRAVO_MODE_LIKE);
ok('switching back to thumbs restores the same thumbs scores', $thumbs, scores());

echo "== 5. album deletion\n";
delete_categories(array($t3));
ok('mode of the deleted album is gone', array($t1), array_keys(like_bravo_get_explicit_modes(true)));
ok('photo C only left in a star album: star photo', 0, like_bravo_get_image_mode($c));
ok('photo B still like + bravo through T2', 2, like_bravo_get_image_mode($b));
$s = scores();
ok('photo C got a star score right after the deletion', core_scores_of(array($c)), $s[$c]);
$before_delete = scores();

echo "== 6. deactivate and activate\n";
$errors = (new plugins())->perform_action('deactivate', 'like_bravo');
ok('deactivate without errors', array(), $errors);
ok('scores handed back to the core formula', core_scores(), scores());
ok('album modes are kept while inactive', 1, count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE)));
ok('stored ratings untouched', $sig, rates_sig());
$errors = (new plugins())->perform_action('activate', 'like_bravo');
ok('activate without errors', array(), $errors);
ok('thumbs scores are back after activation', $before_delete, scores());
add_event_handler('update_rating_score', 'like_bravo_update_rating_score');

echo "== 7. uninstall\n";
$n_rates = count(query2array('SELECT * FROM '.RATE_TABLE));
$errors = (new plugins())->perform_action('uninstall', 'like_bravo');
ok('uninstall without errors', array(), $errors);
ok('plugin table removed', 0, count(query2array("SHOW TABLES LIKE '".LIKE_BRAVO_MODES_TABLE."'")));
ok('plugin settings removed', 0, count(query2array("SELECT * FROM ".CONFIG_TABLE." WHERE param LIKE 'like_bravo%'")));
ok('plugin no longer registered', 0, count(query2array("SELECT * FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'")));
ok('all ratings are still there', $n_rates, count(query2array('SELECT * FROM '.RATE_TABLE)));
ok('scores are the core scores', core_scores(), scores());

echo "== 8. install again\n";
$errors = (new plugins())->perform_action('activate', 'like_bravo');
ok('fresh install without errors', array(), $errors);
ok('table is back and empty', array(1, 0), array(count(query2array("SHOW TABLES LIKE '".LIKE_BRAVO_MODES_TABLE."'")), count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))));
ok('plugin active', 'active', query2array("SELECT state FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'", null, 'state')[0]);

restore_snapshot();
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";

function restore_snapshot() {
  global $snap, $conf; static $done = false; if ($done) return; $done = true;
  if (!count(query2array("SELECT id FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo' AND state = 'active'"))) (new plugins())->perform_action('activate', 'like_bravo');
  $ids = query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'", null, 'id');
  if ($ids) delete_categories($ids);
  pwg_query('DELETE FROM '.RATE_TABLE); pwg_query('DELETE FROM '.LIKE_BRAVO_MODES_TABLE);
  if ($snap['rates']) mass_inserts(RATE_TABLE, array_keys($snap['rates'][0]), $snap['rates']);
  if ($snap['modes']) mass_inserts(LIKE_BRAVO_MODES_TABLE, array_keys($snap['modes'][0]), $snap['modes']);
  conf_update_param('like_bravo', $snap['conf'], true);
  like_bravo_get_explicit_modes(true);
  remove_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  add_event_handler('update_rating_score', 'like_bravo_update_rating_score');
  update_rating_score();
  echo "\nrestored: ".count(query2array('SELECT * FROM '.RATE_TABLE))." rates, ".count(query2array('SELECT * FROM '.LIKE_BRAVO_MODES_TABLE))." modes, test albums left: "
    .count(query2array("SELECT id FROM ".CATEGORIES_TABLE." WHERE name LIKE 'cl_test_%'")).", plugin: "
    .json_encode(query2array("SELECT state FROM ".PLUGINS_TABLE." WHERE id = 'like_bravo'", null, 'state'))."\n";
}

function core_scores_of($ids) { // core formula restricted to the given star photos, returns the score of the first
  $by = array(); foreach (query2array('SELECT element_id e, rate r FROM '.RATE_TABLE.' WHERE element_id IN ('.implode(',', star_ids()).')') as $x) $by[$x['e']][] = (int)$x['r'];
  $n = 0; $s = 0; foreach ($by as $r) { $n += count($r); $s += array_sum($r); } $m = $s/$n; $c = $n/count($by);
  $r = $by[$ids[0]]; return round(($c*$m + array_sum($r))/($c + count($r)), 2); }
function star_ids() { $modes = like_bravo_get_all_image_modes(); $ids = array(); foreach (query2array('SELECT DISTINCT element_id e FROM '.RATE_TABLE, null, 'e') as $id) if (empty($modes[$id])) $ids[] = (int)$id; return $ids; }
