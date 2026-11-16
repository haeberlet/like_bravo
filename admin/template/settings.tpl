{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_css path=$LIKE_BRAVO_CSS}

<form method="post" action="{$F_ACTION}" class="properties">

<div id="configContent">

  <fieldset class="no-border">
    <legend>{'Icons'|translate}</legend>
    <ul>
      <li>
        <strong>{'Icon for "Like"'|translate}</strong>
        <div class="like-bravo-icon-choice">
{foreach from=$LIKE_BRAVO_VOTE_ICONS item=svg key=name}
          <label title="{$name}">
            <input type="radio" name="icon_like" value="{$name}"{if $like_bravo.icon_like == $name} checked="checked"{/if}>
            <span>{$svg}</span>
          </label>
{/foreach}
        </div>
      </li>
      <li>
        <strong>{'Icon for "Bravo"'|translate}</strong>
        <div class="like-bravo-icon-choice">
{foreach from=$LIKE_BRAVO_VOTE_ICONS item=svg key=name}
          <label title="{$name}">
            <input type="radio" name="icon_bravo" value="{$name}"{if $like_bravo.icon_bravo == $name} checked="checked"{/if}>
            <span>{$svg}</span>
          </label>
{/foreach}
        </div>
      </li>
      <li>
        <strong>{'Icon for "Remove my vote"'|translate}</strong>
        <div class="like-bravo-icon-choice">
{foreach from=$LIKE_BRAVO_UNDO_ICONS item=svg key=name}
          <label title="{$name}">
            <input type="radio" name="icon_undo" value="{$name}"{if $like_bravo.icon_undo == $name} checked="checked"{/if}>
            <span>{if $name == 'none'}{'no separate icon'|translate}{else}{$svg}{/if}</span>
          </label>
{/foreach}
        </div>
        <span class="like-bravo-hint">{'A click on the active icon always removes the vote, with or without a separate icon.'|translate}</span>
      </li>
    </ul>
  </fieldset>

  <fieldset class="no-border">
    <legend>{'Rating'|translate}</legend>
    <ul>
      <li>
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" name="allow_own_photos"{if $like_bravo.allow_own_photos} checked="checked"{/if}>
          {'Members may rate their own photos'|translate}
        </label>
      </li>
      <li>
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" name="allow_guests"{if $like_bravo.allow_guests} checked="checked"{/if}>
          {'Guests may like'|translate}
        </label>
{if !$LIKE_BRAVO_RATE_ANONYMOUS}
        <span class="like-bravo-hint like-bravo-hint-block like-bravo-warning"><i class="icon-attention"></i> {'No effect at the moment: "Rating by guests" is switched off in the Piwigo configuration.'|translate}</span>
{/if}
        <span class="like-bravo-hint like-bravo-hint-block">{'Piwigo tells guests apart by their network address only. Visitors of the same network (same first three parts of an IPv4 address, e.g. a club house or an office) share one vote. Whoever changes the address (VPN, mobile network) and deletes the cookie can vote again. With IPv6 every single address counts, and many connections change theirs regularly.'|translate}</span>
      </li>
    </ul>
  </fieldset>

  <fieldset class="no-border">
    <legend>{'Names of voters'|translate}</legend>
    <ul>
      <li>
        <label>
          {'Show the names of voters'|translate}
          <select name="show_names">
{foreach from=$LIKE_BRAVO_SHOW_NAMES item=label key=value}
            <option value="{$value}"{if $like_bravo.show_names == $value} selected="selected"{/if}>{$label}</option>
{/foreach}
          </select>
        </label>
        <span class="like-bravo-hint like-bravo-hint-block">{'Votes cast before this was switched on are shown with their names as well. Guests have no name, they appear as a number.'|translate}</span>
      </li>
      <li>
        <label>
          {'Names in the short form'|translate}
          <input type="number" name="names_limit" min="1" max="10" step="1" value="{$like_bravo.names_limit}">
        </label>
        <span class="like-bravo-hint like-bravo-hint-block">{'The short form appears when the mouse is over a counter, e.g. "Liked by Anna, Ben, Carl and 4 others". A click on the counter shows all names.'|translate}</span>
      </li>
    </ul>
  </fieldset>

</div>

<div class="savebar-footer">
  <div class="savebar-footer-start">
  </div>
  <div class="savebar-footer-end">
{if isset($save_success)}
    <div class="savebar-footer-block">
      <div class="badge info-message">
        <i class="icon-ok"></i>{$save_success}
      </div>
    </div>
{/if}
    <div class="savebar-footer-block">
      <button class="buttonLike" type="submit" name="submit"><i class="icon-floppy"></i> {'Save Settings'|translate}</button>
    </div>
  </div>
  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN}">
</div>

</form>
