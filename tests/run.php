<?php
/**
 * Tests for the pure business rules. Needs no Piwigo and no database.
 *
 *   php tests/run.php
 */

if (PHP_SAPI !== 'cli')
{
  die('Hacking attempt!');
}

define('PHPWG_ROOT_PATH', './');
require dirname(__DIR__) . '/include/functions_logic.inc.php';

$failures = 0;
$checks = 0;

function check($label, $expected, $actual)
{
  global $failures, $checks;

  $checks++;
  if ($expected !== $actual)
  {
    $failures++;
    echo 'FAIL  ' . $label . "\n"
      . '      expected ' . var_export($expected, true) . "\n"
      . '      actual   ' . var_export($actual, true) . "\n";
  }
}

const INACTIVE = LIKE_BRAVO_MODE_INACTIVE;
const LIKE = LIKE_BRAVO_MODE_LIKE;
const BRAVO = LIKE_BRAVO_MODE_BOTH;

// --- album mode inheritance -------------------------------------------------
// tree: 1 > 5 > 12 > 30
$explicit = array(1 => LIKE, 12 => BRAVO);

check('own explicit mode wins',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12', $explicit));
check('inherits from nearest parent with a mode',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12,30', $explicit));
check('skips parents without a mode',
  array('mode' => LIKE, 'source' => 1),
  like_bravo_resolve_mode('1,5', $explicit));
check('root album with explicit mode',
  array('mode' => LIKE, 'source' => 1),
  like_bravo_resolve_mode('1', $explicit));
check('nothing set anywhere is inactive',
  array('mode' => INACTIVE, 'source' => null),
  like_bravo_resolve_mode('2,7', $explicit));
check('explicit inactive stops inheritance',
  array('mode' => INACTIVE, 'source' => 5),
  like_bravo_resolve_mode('1,5,40', array(1 => BRAVO, 5 => INACTIVE)));
check('child can re-enable below an inactive album',
  array('mode' => LIKE, 'source' => 40),
  like_bravo_resolve_mode('1,5,40', array(1 => BRAVO, 5 => INACTIVE, 40 => LIKE)));
check('modes read as strings from the database',
  array('mode' => BRAVO, 'source' => 12),
  like_bravo_resolve_mode('1,5,12', array('12' => '2')));
check('empty uppercats',
  array('mode' => INACTIVE, 'source' => null),
  like_bravo_resolve_mode('', $explicit));
check('album id 10 does not match id 1',
  array('mode' => INACTIVE, 'source' => null),
  like_bravo_resolve_mode('10', array(1 => LIKE)));

// --- mode of a photo --------------------------------------------------------
check('photo without album is inactive', INACTIVE, like_bravo_combine_modes(array()));
check('single album', LIKE, like_bravo_combine_modes(array(LIKE)));
check('highest mode wins', BRAVO, like_bravo_combine_modes(array(INACTIVE, BRAVO, LIKE)));
check('like beats inactive', LIKE, like_bravo_combine_modes(array(INACTIVE, LIKE)));
check('all inactive', INACTIVE, like_bravo_combine_modes(array(INACTIVE, INACTIVE)));

// --- how stored rates count -------------------------------------------------
foreach (array(0, 1) as $rate)
{
  check("rate $rate is dormant in like + bravo", null, like_bravo_classify_rate($rate, BRAVO));
  check("rate $rate is dormant in like", null, like_bravo_classify_rate($rate, LIKE));
}
check('2 is a like in like + bravo', 'like', like_bravo_classify_rate(2, BRAVO));
check('3 is a like in like + bravo', 'like', like_bravo_classify_rate(3, BRAVO));
check('4 is a bravo in like + bravo', 'bravo', like_bravo_classify_rate(4, BRAVO));
check('5 is a bravo in like + bravo', 'bravo', like_bravo_classify_rate(5, BRAVO));
foreach (array(2, 3, 4, 5) as $rate)
{
  check("$rate is a like in like", 'like', like_bravo_classify_rate($rate, LIKE));
}
check('nothing counts in inactive', null, like_bravo_classify_rate(5, INACTIVE));
check('rate as string from the database', 'bravo', like_bravo_classify_rate('5', BRAVO));

check('counts in like + bravo',
  array('likes' => 3, 'bravos' => 3, 'dormant' => 2),
  like_bravo_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), BRAVO));
check('counts in like',
  array('likes' => 6, 'bravos' => 0, 'dormant' => 2),
  like_bravo_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), LIKE));
check('no votes',
  array('likes' => 0, 'bravos' => 0, 'dormant' => 0),
  like_bravo_count_votes(array(), BRAVO));

// --- score ------------------------------------------------------------------
check('a like is 2 points', 2, like_bravo_points(1, 0));
check('a bravo is 4 points', 4, like_bravo_points(0, 1));
check('3 likes and 2 bravos', 14, like_bravo_points(3, 2));
check('no votes, no points', 0, like_bravo_points(0, 0));

check('no points give no score', null, like_bravo_score(0, 10));
check('average points give half of the maximum', 2.5, like_bravo_score(10, 10));
check('half of the average', 1.67, like_bravo_score(5, 10));      // 25/15
check('twice the average', 3.33, like_bravo_score(20, 10));       // 100/30
check('four times the average', 4.0, like_bravo_score(40, 10));   // 200/50
check('same ratio, same score in a larger gallery', like_bravo_score(20, 10), like_bravo_score(600, 300));

$previous = 0;
$monotonic = true;
for ($points = 1; $points <= 1000; $points++)
{
  $score = like_bravo_score($points, 10);
  if ($score < $previous or $score >= 5)
  {
    $monotonic = false;
  }
  $previous = $score;
}
check('score never decreases and stays below 5', true, $monotonic);

// --- vote levels ------------------------------------------------------------
check('levels in inactive', array(), like_bravo_allowed_levels(INACTIVE));
check('levels in like', array('none', 'like'), like_bravo_allowed_levels(LIKE));
check('levels in like + bravo', array('none', 'like', 'bravo'), like_bravo_allowed_levels(BRAVO));
check('like is stored as 2', 2, like_bravo_level_to_rate('like'));
check('bravo is stored as 4', 4, like_bravo_level_to_rate('bravo'));
check('none is not stored', null, like_bravo_level_to_rate('none'));
check('unknown level is not stored', null, like_bravo_level_to_rate('dislike'));
check('a stored like reads back as like',
  'like', like_bravo_classify_rate(like_bravo_level_to_rate('like'), BRAVO));
check('a stored bravo reads back as bravo',
  'bravo', like_bravo_classify_rate(like_bravo_level_to_rate('bravo'), BRAVO));

// --- configuration ----------------------------------------------------------
$defaults = like_bravo_default_conf();
check('missing configuration gives defaults', $defaults, like_bravo_normalize_conf(false));
check('stored values are kept',
  'heart', like_bravo_normalize_conf(array('icon_like' => 'heart'))['icon_like']);
check('missing keys are filled',
  $defaults['icon_bravo'], like_bravo_normalize_conf(array('icon_like' => 'heart'))['icon_bravo']);
check('unknown keys are dropped',
  false, array_key_exists('foo', like_bravo_normalize_conf(array('foo' => 'bar'))));
check('score constant of earlier versions is dropped',
  false, array_key_exists('score_constant', like_bravo_normalize_conf(array('score_constant' => 7))));
check('normalizing twice changes nothing',
  like_bravo_normalize_conf(array('icon_like' => 'heart', 'allow_guests' => 1)),
  like_bravo_normalize_conf(like_bravo_normalize_conf(array('icon_like' => 'heart', 'allow_guests' => 1))));

check('unknown like icon falls back',
  $defaults['icon_like'], like_bravo_normalize_conf(array('icon_like' => '../x'))['icon_like']);
check('undo icon is not a vote icon',
  $defaults['icon_bravo'], like_bravo_normalize_conf(array('icon_bravo' => 'ban'))['icon_bravo']);
check('valid icons are kept',
  array('heart', 'trophy', 'cross'),
  array_values(array_intersect_key(
    like_bravo_normalize_conf(array('icon_like' => 'heart', 'icon_bravo' => 'trophy', 'icon_undo' => 'cross')),
    array('icon_like' => 1, 'icon_bravo' => 1, 'icon_undo' => 1))));
check('label settings of earlier versions are dropped',
  false, isset(like_bravo_normalize_conf(array('label_bravo' => 'Super'))['label_bravo']));
check('default icons are selectable', true,
  in_array($defaults['icon_like'], like_bravo_vote_icon_names())
  and in_array($defaults['icon_bravo'], like_bravo_vote_icon_names())
  and in_array($defaults['icon_undo'], like_bravo_undo_icon_names()));

check('guests are not allowed by default', false, $defaults['allow_guests']);
check('guest option is stored as a boolean',
  true, like_bravo_normalize_conf(array('allow_guests' => '1'))['allow_guests']);

// --- anonymous visitors -----------------------------------------------------
check('guests take part: plugin option and Piwigo option', true, like_bravo_guests_take_part(true, true));
check('guests do not take part without the plugin option', false, like_bravo_guests_take_part(false, true));
check('guests do not take part without "Rating by guests"', false, like_bravo_guests_take_part(true, false));
check('guests do not take part with both options off', false, like_bravo_guests_take_part(false, false));

check('IPv4: last part is dropped', '203.0.113', like_bravo_anonymous_id('203.0.113.7'));
check('IPv4: same /24 network, same identifier',
  like_bravo_anonymous_id('203.0.113.7'), like_bravo_anonymous_id('203.0.113.250'));
check('IPv4: other network, other identifier',
  false, like_bravo_anonymous_id('203.0.113.7') == like_bravo_anonymous_id('203.0.114.7'));
check('IPv6: whole address', '2001:db8::1', like_bravo_anonymous_id('2001:db8::1'));
check('IPv6: neighbours are different visitors',
  false, like_bravo_anonymous_id('2001:db8::1') == like_bravo_anonymous_id('2001:db8::2'));
check('IPv4 in IPv6: last part is dropped', '::ffff:1.2.3', like_bravo_anonymous_id('::ffff:1.2.3.4'));
check('missing address', '', like_bravo_anonymous_id(null));

// --- scores of the whole gallery --------------------------------------------
function rows($element_id, $rates, $is_guest = 0)
{
  $rows = array();
  foreach (array_count_values($rates) as $rate => $n)
  {
    $rows[] = array('element_id' => (string)$element_id, 'rate' => (string)$rate, 'is_guest' => (string)$is_guest, 'n' => (string)$n);
  }
  return $rows;
}

// photos 1 and 2 are star photos: m = 4, C = 4
$star_rows = array_merge(rows(1, array(5, 5, 5, 5)), rows(2, array(3, 3, 3, 3)));
$star_only = like_bravo_compute_scores($star_rows, array());
check('star photo: core bayesian score, high',
  array('score' => 4.5, 'average' => 5.0, 'count' => 4), $star_only[1]);
check('star photo: core bayesian score, low',
  array('score' => 3.5, 'average' => 3.0, 'count' => 4), $star_only[2]);

// same result as the Piwigo core formula, computed independently
$core = function($by_item) {
  $count = 0; $sum = 0;
  foreach ($by_item as $rates) { $count += count($rates); $sum += array_sum($rates); }
  $m = $sum / $count; $c = $count / count($by_item);
  $scores = array();
  foreach ($by_item as $id => $rates) { $scores[$id] = round(($c * $m + array_sum($rates)) / ($c + count($rates)), 2); }
  return $scores;
};
$sample = array(11 => array(0, 5, 4), 12 => array(2), 13 => array(5, 5, 4, 4, 3, 1), 14 => array(3, 3));
$sample_rows = array();
foreach ($sample as $id => $rates) { $sample_rows = array_merge($sample_rows, rows($id, $rates)); }
check('star scores equal the core formula',
  $core($sample), array_map(function($r) { return $r['score']; }, like_bravo_compute_scores($sample_rows, array())));

// photos 3, 4 and 5 are thumbs photos with 2, 8 and 20 points: average 10
$thumbs_rows = array_merge(
  rows(3, array(2)),                         // a new like
  rows(4, array(3, 3, 5, 0, 1)),             // older star rates: two likes, a bravo, two dormant
  rows(5, array(4, 4, 4, 4, 4)));
$thumbs_modes = array(3 => BRAVO, 4 => BRAVO, 5 => BRAVO);
$mixed = like_bravo_compute_scores(array_merge($star_rows, $thumbs_rows), $thumbs_modes);
check('thumbs votes do not move star scores',
  array($star_only[1], $star_only[2]), array($mixed[1], $mixed[2]));
check('thumbs photo below the average',
  array('score' => 0.83, 'average' => null, 'count' => 1, 'likes' => 1, 'bravos' => 0), $mixed[3]);   // 10/12
check('thumbs photo near the average, dormant rates left out',
  array('score' => 2.22, 'average' => null, 'count' => 3, 'likes' => 2, 'bravos' => 1), $mixed[4]);   // 40/18
check('thumbs photo with twice the average',
  array('score' => 3.33, 'average' => null, 'count' => 5, 'likes' => 0, 'bravos' => 5), $mixed[5]);   // 100/30
check('star votes do not move thumbs scores',
  array($mixed[3], $mixed[4], $mixed[5]),
  array_values(like_bravo_compute_scores($thumbs_rows, $thumbs_modes)));

// ten times as many voters: same scores
$large_rows = array();
foreach ($thumbs_rows as $row)
{
  $row['n'] = (string)(10 * $row['n']);
  $large_rows[] = $row;
}
check('scores do not depend on the size of the gallery',
  array(0.83, 2.22, 3.33),
  array_values(array_column(like_bravo_compute_scores($large_rows, $thumbs_modes), 'score')));

check('same votes in like mode count as likes only',
  array('score' => 2.5, 'average' => null, 'count' => 3, 'likes' => 3, 'bravos' => 0),              // P 2, 6, 10: average 6
  like_bravo_compute_scores($thumbs_rows, array(3 => LIKE, 4 => LIKE, 5 => LIKE))[4]);
$with_unrated = like_bravo_compute_scores(array_merge($thumbs_rows, rows(6, array(0, 1))), $thumbs_modes + array(6 => BRAVO));
check('thumbs photo with dormant votes only has no score',
  array('score' => null, 'average' => null, 'count' => 0, 'likes' => 0, 'bravos' => 0), $with_unrated[6]);
check('and does not lower the average',
  array($mixed[3], $mixed[4], $mixed[5]), array($with_unrated[3], $with_unrated[4], $with_unrated[5]));

// photo 7: one member like, guests: two bravos, a like and a dormant rate; photo 8: one member bravo
$guest_rows = array_merge(rows(7, array(2)), rows(7, array(4, 4, 2, 1), 1), rows(8, array(4)));
$guest_modes = array(7 => BRAVO, 8 => BRAVO);
check('guest rates do not count on thumbs photos',
  array('score' => 2.0, 'average' => null, 'count' => 1, 'likes' => 1, 'bravos' => 0),               // P 2, average 3: 10/5
  like_bravo_compute_scores($guest_rows, $guest_modes)[7]);
check('guest rates count on thumbs photos if guests take part',
  array('score' => 3.0, 'average' => null, 'count' => 4, 'likes' => 2, 'bravos' => 2),               // P 12, average 8: 60/20
  like_bravo_compute_scores($guest_rows, $guest_modes, true)[7]);
check('guest rates still count on star photos',
  5, like_bravo_compute_scores($guest_rows, array())[7]['count']);
check('switching a photo to thumbs and back loses nothing',
  $star_only, like_bravo_compute_scores($star_rows, array(1 => INACTIVE)));
check('no rates at all', array(), like_bravo_compute_scores(array(), array(1 => BRAVO)));
check('a single rated thumbs photo is its own average',
  2.5, like_bravo_compute_scores(rows(9, array(2, 2, 2, 4)), array(9 => BRAVO))[9]['score']);

echo ($checks - $failures) . '/' . $checks . " checks passed\n";
exit($failures > 0 ? 1 : 0);
