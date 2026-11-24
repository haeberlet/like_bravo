{* Like & Bravo: page "All feedback". A feed in one column, under headings of
   days, and a side column with an overview, the filters and the photos with
   the most feedback; on phones the side column is a strip above the feed.
   The entries have the markup of the list of the bell: the thumbnail leads
   to the photo, the name of a member to his home album (plugin Community),
   if any. js/bell.js marks entries as read in both alike, and loads the
   older entries while scrolling instead of the link "Older".
   data-role="none" and data-ajax="false": SmartPocket (jQuery Mobile) must
   neither restyle the buttons nor load the links by AJAX. *}
{assign var=fb value=$like_bravo_feedback}
<div class="lb-feedback-page">
  <aside class="lb-feedback-side">
    <section class="lb-feedback-box lb-feedback-summary">
      <h3 class="lb-feedback-boxtitle">{'Overview'|translate}</h3>
      <div class="lb-feedback-unread"><span class="lb-feedback-big" data-lb-unread>{$fb.UNREAD}</span> {'unread'|translate}</div>
      <div class="lb-feedback-week">
        <span class="lb-feedback-stats">
          <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.like}{$fb.WEEK.likes}</span>
{if $fb.WITH_BRAVO}
          <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.bravo}{$fb.WEEK.bravos}</span>
{/if}
          <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.comment}{$fb.WEEK.comments}</span>
          <span class="lb-bell-sr">{$fb.WEEK.text}</span>
        </span>
        <span class="lb-feedback-period">{'in the last 7 days'|translate}</span>
      </div>
    </section>

    <section class="lb-feedback-box lb-feedback-filters">
      <h3 class="lb-feedback-boxtitle">{'Show entries'|translate}</h3>
{foreach from=$fb.FILTERS item=choices}
      <div class="lb-feedback-filter" role="group">
{foreach from=$choices item=choice}
        <a href="{$choice.URL}" data-ajax="false"{if $choice.ACTIVE} class="is-active" aria-current="true"{/if}>{$choice.LABEL}{if isset($choice.COUNT)} <span class="lb-feedback-count"{if $choice.LIVE} data-lb-unread{/if}>{$choice.COUNT}</span>{/if}</a>
{/foreach}
      </div>
{/foreach}
      <button type="button" data-role="none" class="lb-bell-markall"{if !$fb.UNREAD} disabled{/if}>{'Mark all as read'|translate}</button>
    </section>

{if !empty($fb.TOP)}
    <section class="lb-feedback-box lb-feedback-top">
      <h3 class="lb-feedback-boxtitle">{'Most feedback'|translate}</h3>
      <ul>
{foreach from=$fb.TOP item=photo}
        <li>
          <a href="{$photo.url}" data-ajax="false">
            <img src="{$photo.thumb}" alt="" width="40" height="40" loading="lazy">
            <span class="lb-feedback-topbody">
              <span class="lb-feedback-topname">{$photo.name}</span>
              <span class="lb-bell-meta">
                <span class="lb-bell-totals">
                  <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.like}{$photo.likes}</span>
{if $fb.WITH_BRAVO}
                  <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.bravo}{$photo.bravos}</span>
{/if}
                  <span class="lb-bell-total" aria-hidden="true">{$fb.ICONS.comment}{$photo.comments}</span>
                  <span class="lb-bell-sr">{$photo.text}</span>
                </span>
              </span>
            </span>
          </a>
        </li>
{/foreach}
      </ul>
    </section>
{/if}
  </aside>

  <div class="lb-feedback-main">
{if $fb.U_NEWEST}
    <a class="lb-feedback-newest" href="{$fb.U_NEWEST}" data-ajax="false">← {'Back to the newest'|translate}</a>
{/if}
    <div class="lb-feedback-feed" data-unread="{if $fb.UNREAD_ONLY}true{else}false{/if}" data-kind="{$fb.KIND}" data-per-page="{$fb.PER_PAGE}" data-days="{$fb.DAYS_JSON|escape:'html'}">
{foreach from=$fb.GROUPS item=group}
      <h3 class="lb-feedback-day">{$group.TITLE}</h3>
      <ul class="lb-bell-list lb-feedback-list" data-day="{$group.DAY}">
{foreach from=$group.ITEMS item=item}
        <li class="lb-bell-entry{if $item.unread} is-unread{/if}" data-key="{$item.KEY_JSON|escape:'html'}" data-cursor="{$item.cursor|escape:'html'}">
          <a class="lb-bell-photo" href="{$item.url}" data-ajax="false"><img class="lb-bell-thumb" src="{$item.thumb}" alt="{$item.thumb_alt|strip_tags|escape:'html'}" width="48" height="48" loading="lazy"></a>
          <span class="lb-bell-body">
            <span class="lb-bell-text">{$item.html}</span>
            <span class="lb-bell-meta">
              <span class="lb-bell-when">{$item.when}</span>
{if $item.kind != 'comment'}
              <span class="lb-bell-sep">·</span>
              <span class="lb-bell-totals">
                <span class="lb-bell-total lb-bell-total-like" aria-hidden="true">{$fb.ICONS.like}{$item.likes}</span>
{if $item.with_bravo}
                <span class="lb-bell-total lb-bell-total-bravo" aria-hidden="true">{$fb.ICONS.bravo}{$item.bravos}</span>
{/if}
                <span class="lb-bell-sr">{$item.totals_text}</span>
              </span>
{/if}
{if $item.unread}
              <span class="lb-bell-sr lb-bell-unread-sr">{'unread'|translate}</span>
{/if}
            </span>
          </span>
{if $item.unread}
          <button type="button" data-role="none" class="lb-bell-dot" title="{'Mark as read'|translate|escape:'html'}" aria-label="{'Mark as read'|translate|escape:'html'}"></button>
{/if}
        </li>
{/foreach}
      </ul>
{foreachelse}
      <p class="lb-feedback-empty">{$fb.EMPTY}</p>
{/foreach}
    </div>

{if $fb.U_OLDER}
    <div class="lb-feedback-more">
      <a href="{$fb.U_OLDER}" rel="next" data-ajax="false">{'Older'|translate} →</a>
    </div>
{/if}
  </div>
</div>
