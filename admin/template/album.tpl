{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}

<form method="post" action="{$F_ACTION}" class="properties">

<div id="configContent">

  <fieldset class="no-border">
    <legend>{'Member rating in this album'|translate}</legend>
    <ul>
      <li>
        <label class="font-checkbox">
          <span class="icon-dot-circled"></span>
          <input type="radio" name="mode" value="inherit"{if $CLUB_LIKES_SELECTED == 'inherit'} checked="checked"{/if}>
          {'Inherited'|translate}
        </label>
        <span class="club-likes-hint">
          &ndash; {'currently'|translate}: <strong>{$CLUB_LIKES_INHERITED_LABEL}</strong>
          {if $CLUB_LIKES_INHERITED_FROM}
            ({'from album'|translate} <a href="{$CLUB_LIKES_INHERITED_FROM.URL}">{$CLUB_LIKES_INHERITED_FROM.NAME}</a>)
          {else}
            ({'no parent album has a setting'|translate})
          {/if}
        </span>
      </li>
{foreach from=$CLUB_LIKES_MODES item=label key=mode}
      <li>
        <label class="font-checkbox">
          <span class="icon-dot-circled"></span>
          <input type="radio" name="mode" value="{$mode}"{if $CLUB_LIKES_SELECTED == "$mode"} checked="checked"{/if}>
          {$label}
        </label>
      </li>
{/foreach}
    </ul>
    <p class="club-likes-hint">{'The setting applies to this album and all its sub-albums, until a sub-album has its own setting.'|translate}</p>
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
