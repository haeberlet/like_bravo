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
    <legend>{'Labels'|translate}</legend>
    <ul>
      <li>
        <label>
          {'Heading'|translate}
          <input type="text" name="label_title" size="30" maxlength="40" value="{$like_bravo.label_title|escape:'html'}" placeholder="{'Member rating'|translate}">
        </label>
      </li>
      <li>
        <label>
          {'Label for "Like"'|translate}
          <input type="text" name="label_like" size="30" maxlength="40" value="{$like_bravo.label_like|escape:'html'}" placeholder="{'Like'|translate}">
        </label>
      </li>
      <li>
        <label>
          {'Label for "Bravo"'|translate}
          <input type="text" name="label_bravo" size="30" maxlength="40" value="{$like_bravo.label_bravo|escape:'html'}" placeholder="{'Bravo'|translate}">
        </label>
      </li>
    </ul>
    <p class="like-bravo-hint">{'Leave a label empty to use the translated default.'|translate}</p>
  </fieldset>

  <fieldset class="no-border">
    <legend>{'Rating'|translate}</legend>
    <ul>
      <li>
        <label>
          {'Score constant'|translate}
          <input type="text" name="score_constant" size="4" maxlength="7" value="{$like_bravo.score_constant}">
        </label>
        <span class="like-bravo-hint">{'Points needed for half of the maximum score. A like is 1 point, a bravo 2 points.'|translate}</span>
      </li>
      <li>
        <label class="font-checkbox">
          <span class="icon-check"></span>
          <input type="checkbox" name="allow_own_photos"{if $like_bravo.allow_own_photos} checked="checked"{/if}>
          {'Members may rate their own photos'|translate}
        </label>
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
