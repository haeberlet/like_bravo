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

const INACTIVE = CLUB_LIKES_MODE_INACTIVE;
const LIKE = CLUB_LIKES_MODE_LIKE;
const BRAVO = CLUB_LIKES_MODE_LIKE_BRAVO;

// --- album mode inheritance -------------------------------------------------
// tree: 1 > 5 > 12 > 30
$explicit = array(1 => LIKE, 12 => BRAVO);

check('own explicit mode wins',
  array('mode' => BRAVO, 'source' => 12),
  club_likes_resolve_mode('1,5,12', $explicit));
check('inherits from nearest parent with a mode',
  array('mode' => BRAVO, 'source' => 12),
  club_likes_resolve_mode('1,5,12,30', $explicit));
check('skips parents without a mode',
  array('mode' => LIKE, 'source' => 1),
  club_likes_resolve_mode('1,5', $explicit));
check('root album with explicit mode',
  array('mode' => LIKE, 'source' => 1),
  club_likes_resolve_mode('1', $explicit));
check('nothing set anywhere is inactive',
  array('mode' => INACTIVE, 'source' => null),
  club_likes_resolve_mode('2,7', $explicit));
check('explicit inactive stops inheritance',
  array('mode' => INACTIVE, 'source' => 5),
  club_likes_resolve_mode('1,5,40', array(1 => BRAVO, 5 => INACTIVE)));
check('child can re-enable below an inactive album',
  array('mode' => LIKE, 'source' => 40),
  club_likes_resolve_mode('1,5,40', array(1 => BRAVO, 5 => INACTIVE, 40 => LIKE)));
check('modes read as strings from the database',
  array('mode' => BRAVO, 'source' => 12),
  club_likes_resolve_mode('1,5,12', array('12' => '2')));
check('empty uppercats',
  array('mode' => INACTIVE, 'source' => null),
  club_likes_resolve_mode('', $explicit));
check('album id 10 does not match id 1',
  array('mode' => INACTIVE, 'source' => null),
  club_likes_resolve_mode('10', array(1 => LIKE)));

// --- mode of a photo --------------------------------------------------------
check('photo without album is inactive', INACTIVE, club_likes_combine_modes(array()));
check('single album', LIKE, club_likes_combine_modes(array(LIKE)));
check('highest mode wins', BRAVO, club_likes_combine_modes(array(INACTIVE, BRAVO, LIKE)));
check('like beats inactive', LIKE, club_likes_combine_modes(array(INACTIVE, LIKE)));
check('all inactive', INACTIVE, club_likes_combine_modes(array(INACTIVE, INACTIVE)));

// --- how stored rates count -------------------------------------------------
foreach (array(0, 1, 2) as $rate)
{
  check("rate $rate is dormant in like + bravo", null, club_likes_classify_rate($rate, BRAVO));
  check("rate $rate is dormant in like", null, club_likes_classify_rate($rate, LIKE));
}
check('3 is a like in like + bravo', 'like', club_likes_classify_rate(3, BRAVO));
check('4 is a bravo in like + bravo', 'bravo', club_likes_classify_rate(4, BRAVO));
check('5 is a bravo in like + bravo', 'bravo', club_likes_classify_rate(5, BRAVO));
check('3 is a like in like', 'like', club_likes_classify_rate(3, LIKE));
check('4 is a like in like', 'like', club_likes_classify_rate(4, LIKE));
check('5 is a like in like', 'like', club_likes_classify_rate(5, LIKE));
check('nothing counts in inactive', null, club_likes_classify_rate(5, INACTIVE));
check('rate as string from the database', 'bravo', club_likes_classify_rate('5', BRAVO));

check('counts in like + bravo',
  array('likes' => 2, 'bravos' => 3, 'dormant' => 3),
  club_likes_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), BRAVO));
check('counts in like',
  array('likes' => 5, 'bravos' => 0, 'dormant' => 3),
  club_likes_count_votes(array(0, 1, 2, 3, 3, 4, 5, 5), LIKE));
check('no votes',
  array('likes' => 0, 'bravos' => 0, 'dormant' => 0),
  club_likes_count_votes(array(), BRAVO));

// --- score ------------------------------------------------------------------
check('no counting vote gives no score', null, club_likes_score(0, 0, 5));
check('one like', 0.83, club_likes_score(1, 0, 5));           // 5*1/6
check('one bravo equals two likes', club_likes_score(2, 0, 5), club_likes_score(0, 1, 5));
check('five points give half of the maximum', 2.5, club_likes_score(5, 0, 5));
check('12 likes and 3 bravos', 3.91, club_likes_score(12, 3, 5)); // 5*18/23
check('other constant', 1.67, club_likes_score(5, 0, 10));    // 5*5/15

$previous = 0;
$monotonic = true;
for ($likes = 1; $likes <= 500; $likes++)
{
  $score = club_likes_score($likes, 0, 5);
  if ($score < $previous or $score >= 5)
  {
    $monotonic = false;
  }
  $previous = $score;
}
check('score never decreases and stays below 5', true, $monotonic);

// --- vote levels ------------------------------------------------------------
check('levels in inactive', array(), club_likes_allowed_levels(INACTIVE));
check('levels in like', array('none', 'like'), club_likes_allowed_levels(LIKE));
check('levels in like + bravo', array('none', 'like', 'bravo'), club_likes_allowed_levels(BRAVO));
check('like is stored as 3', 3, club_likes_level_to_rate('like'));
check('bravo is stored as 5', 5, club_likes_level_to_rate('bravo'));
check('none is not stored', null, club_likes_level_to_rate('none'));
check('unknown level is not stored', null, club_likes_level_to_rate('dislike'));
check('a stored like reads back as like',
  'like', club_likes_classify_rate(club_likes_level_to_rate('like'), BRAVO));
check('a stored bravo reads back as bravo',
  'bravo', club_likes_classify_rate(club_likes_level_to_rate('bravo'), BRAVO));

// --- configuration ----------------------------------------------------------
$defaults = club_likes_default_conf();
check('missing configuration gives defaults', $defaults, club_likes_normalize_conf(false));
check('stored values are kept',
  10.0, club_likes_normalize_conf(array('score_constant' => '10'))['score_constant']);
check('missing keys are filled',
  $defaults['icon_like'], club_likes_normalize_conf(array('score_constant' => 10))['icon_like']);
check('unknown keys are dropped',
  false, array_key_exists('foo', club_likes_normalize_conf(array('foo' => 'bar'))));
check('invalid score constant falls back',
  5.0, club_likes_normalize_conf(array('score_constant' => 0))['score_constant']);
check('normalizing twice changes nothing',
  club_likes_normalize_conf(array('score_constant' => 7)),
  club_likes_normalize_conf(club_likes_normalize_conf(array('score_constant' => 7))));

check('unknown like icon falls back',
  $defaults['icon_like'], club_likes_normalize_conf(array('icon_like' => '../x'))['icon_like']);
check('undo icon is not a vote icon',
  $defaults['icon_bravo'], club_likes_normalize_conf(array('icon_bravo' => 'ban'))['icon_bravo']);
check('valid icons are kept',
  array('heart', 'trophy', 'cross'),
  array_values(array_intersect_key(
    club_likes_normalize_conf(array('icon_like' => 'heart', 'icon_bravo' => 'trophy', 'icon_undo' => 'cross')),
    array('icon_like' => 1, 'icon_bravo' => 1, 'icon_undo' => 1))));
check('labels are trimmed and stripped of markup',
  'Super', club_likes_normalize_conf(array('label_bravo' => '  <b>Super</b> '))['label_bravo']);
check('labels are cut at 40 characters',
  40, strlen(club_likes_normalize_conf(array('label_like' => str_repeat('a', 60)))['label_like']));
check('non-string label becomes empty',
  '', club_likes_normalize_conf(array('label_title' => array('x')))['label_title']);
check('non-numeric score constant falls back',
  5.0, club_likes_normalize_conf(array('score_constant' => 'abc'))['score_constant']);
check('score constant 2.5 is valid', true, club_likes_is_valid_score_constant('2.5'));
check('score constant 0 is invalid', false, club_likes_is_valid_score_constant(0));
check('negative score constant is invalid', false, club_likes_is_valid_score_constant(-1));
check('huge score constant is invalid', false, club_likes_is_valid_score_constant(100000));
check('default icons are selectable', true,
  in_array($defaults['icon_like'], club_likes_vote_icon_names())
  and in_array($defaults['icon_bravo'], club_likes_vote_icon_names())
  and in_array($defaults['icon_undo'], club_likes_undo_icon_names()));

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
$star_only = club_likes_compute_scores($star_rows, array(), 5);
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
  $core($sample), array_map(function($r) { return $r['score']; }, club_likes_compute_scores($sample_rows, array(), 5)));

// photo 3 is a thumbs photo with many votes
$mixed_rows = array_merge($star_rows, rows(3, array_merge(array_fill(0, 30, 3), array_fill(0, 10, 5), array(0, 1, 2))));
$mixed = club_likes_compute_scores($mixed_rows, array(3 => BRAVO), 5);
check('thumbs votes do not move star scores',
  array($star_only[1], $star_only[2]), array($mixed[1], $mixed[2]));
check('thumbs photo: likes, bravos and score',
  array('score' => 4.55, 'average' => null, 'count' => 40, 'likes' => 30, 'bravos' => 10), $mixed[3]); // P=50, 250/55
check('same votes in like mode count as likes only',
  array('score' => 4.44, 'average' => null, 'count' => 40, 'likes' => 40, 'bravos' => 0),          // P=40, 200/45
  club_likes_compute_scores($mixed_rows, array(3 => LIKE), 5)[3]);
check('thumbs photo with dormant votes only has no score',
  array('score' => null, 'average' => null, 'count' => 0, 'likes' => 0, 'bravos' => 0),
  club_likes_compute_scores(rows(4, array(0, 1, 2)), array(4 => BRAVO), 5)[4]);
check('guest rates do not count on thumbs photos',
  array('score' => 0.83, 'average' => null, 'count' => 1, 'likes' => 1, 'bravos' => 0),
  club_likes_compute_scores(array_merge(rows(5, array(3)), rows(5, array(5, 5, 3), 1)), array(5 => BRAVO), 5)[5]);
check('guest rates still count on star photos',
  4, club_likes_compute_scores(array_merge(rows(6, array(3)), rows(6, array(5, 5, 3), 1)), array(), 5)[6]['count']);
check('score constant is applied',
  2.5, club_likes_compute_scores(rows(7, array_fill(0, 10, 3)), array(7 => LIKE), 10)[7]['score']);
check('switching a photo to thumbs and back loses nothing',
  $star_only, club_likes_compute_scores($star_rows, array(1 => INACTIVE), 5));
check('no rates at all', array(), club_likes_compute_scores(array(), array(1 => BRAVO), 5));
check('only thumbs photos in the gallery',
  2.5, club_likes_compute_scores(rows(8, array(3, 3, 3, 5)), array(8 => BRAVO), 5)[8]['score']);

echo ($checks - $failures) . '/' . $checks . " checks passed\n";
exit($failures > 0 ? 1 : 0);
