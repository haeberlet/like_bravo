# How Like & Bravo rates photos

This page explains what the plugin stores when somebody votes, how it turns likes
and bravos into Piwigo's rating score, and what happens when an album is switched
between stars and likes. You do not need any of it to use the plugin; it is here
for administrators who want to know why a photo ranks where it does.

## The short version

* A like is stored as the rating 2, a bravo as the rating 4.
* A photo's points are 2 for every like and 4 for every bravo.
* Its score is `5 × points / (points + average)`, where the average is the mean
  number of points of all rated like photos in the gallery.
* Photos in albums with stars are rated exactly as Piwigo rates them.

## What is stored

The plugin has no vote table of its own. A vote is a row in Piwigo's rating
table, the same row a star rating would create:

| Vote | Stored rating | Star on Piwigo's scale of six (0 to 5) |
|---|---|---|
| Like | 2 | third star |
| Bravo | 4 | fifth star |
| vote taken back | row deleted | – |

The values are chosen so that an album still makes sense if it is switched to
stars later. A like sits above the two lowest stars, and the top star stays free
for a rating that is better than a bravo.

## From votes to a score

Piwigo keeps one score per photo, between 0 and 5, and uses it to sort photos,
for example on the page "Best rated". For stars it is a weighted average. An
average does not work for likes: nobody votes "I do not like this", so an average
only shows the mix of likes and bravos, not how many there are. With likes only,
every rated photo would have the same score.

The plugin therefore counts points:

```
points  = 2 × likes + 4 × bravos
score   = 5 × points / (points + average)
average = mean points of all like photos that have at least one counting vote
```

The score rises with every vote and never reaches 5. Because it is measured
against the gallery's own average, the scale is the same in a gallery with ten
voters and in one with a thousand:

| Points of the photo | Score |
|---|---|
| half the average | 1.67 |
| the average | 2.50 |
| twice the average | 3.33 |
| four times the average | 4.00 |
| nine times the average | 4.50 |

An example from a gallery whose like photos have 10 points on average:

| Photo | Likes | Bravos | Points | Score |
|---|---|---|---|---|
| A | 1 | 0 | 2 | 0.83 |
| B | 2 | 1 | 8 | 2.22 |
| C | 0 | 5 | 20 | 3.33 |
| D | 10 | 5 | 40 | 4.00 |

Three consequences are worth knowing:

* **Scores are relative.** When other photos collect votes, the average rises and
  the score of an unchanged photo can drop slightly. The order of the like photos
  among themselves never changes because of that: more points always means a
  higher score.
* **A photo without a counting vote has no score.** It is "not rated", like a
  photo without stars.
* **The first rated photo scores 2.5.** It is its own average. The scale settles
  as more photos are rated.

On the photo page the score is not shown for like photos, only the counters. It
matters wherever Piwigo sorts by rating.

## Photos with stars

Albums that are not in a like mode keep Piwigo's rating: a Bayesian average that
pulls photos with few ratings towards the gallery mean. The plugin computes it
with Piwigo's formula, with one difference: the gallery mean is taken from star
photos only. Likes and bravos elsewhere in the gallery never move the score of a
star photo, and star ratings never move the score of a like photo.

On pages that mix both kinds, such as "Best rated", like photos and star photos
are sorted by the same 0 to 5 score. A like photo needs four times the average
number of points to stand next to a star photo rated 4.0.

## Switching an album

Ratings are never rewritten. Switching an album only changes how the stored
ratings are read, so switching back loses nothing.

**From stars to likes.** Existing star ratings are counted like this:

| Stored rating | Album in "Like + Bravo" | Album in "Like only" |
|---|---|---|
| 0 or 1 | dormant | dormant |
| 2 or 3 | like | like |
| 4 or 5 | bravo | like |

Dormant ratings stay in the database but are not counted and not shown. Native
star rating is refused on like photos, so no new dormant ratings appear.

**From likes to stars.** Likes become ratings of 2, bravos ratings of 4, and
Piwigo's formula applies again, dormant ratings included.

**Photos in several albums.** A photo has one mode, the highest of all its
albums: Like + Bravo before Like only before Inactive. It is the same wherever
the photo is opened from.

## Votes of guests

Guests can vote only if both the plugin option "Guests may like" and Piwigo's
option "Rating by guests" are on. Their votes then earn the same points as those
of members. If either option is switched off, guest votes stay stored but become
dormant.

Piwigo identifies a guest by the network address only: all visitors of one IPv4
network (the first three parts of the address) share a single vote, and every
IPv6 address counts on its own. Accounts with the status "generic" are treated
like guests.

## When scores are recomputed

All scores are recomputed from the stored ratings

* with every vote and every vote taken back,
* when the mode of an album changes or an album is deleted,
* when "Guests may like" is switched,
* when the plugin is activated.

Deactivating the plugin recomputes them with Piwigo's formula for the whole
gallery.

Piwigo does not notify plugins when a photo is moved to another album or when
"Rating by guests" is changed in the Piwigo configuration. In both cases the
scores follow with the next recomputation.
