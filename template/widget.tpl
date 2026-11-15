{combine_css path=$club_likes_widget.CSS_PATH}
{if $club_likes_widget.BUTTONS}
{combine_script id='club_likes' load='footer' path=$club_likes_widget.JS_PATH}
{/if}

<div id="clubLikes" class="club-likes"
  data-image-id="{$club_likes_widget.IMAGE_ID}"
  data-level="{$club_likes_widget.LEVEL}"
  data-url="{$club_likes_widget.WS_URL|escape:'html'}"
  data-token="{$club_likes_widget.PWG_TOKEN}"
  data-str-like="{'%d like'|translate|escape:'html'}"
  data-str-likes="{'%d likes'|translate|escape:'html'}"
  data-str-bravo="{'%d bravo'|translate|escape:'html'}"
  data-str-bravos="{'%d bravos'|translate|escape:'html'}"
  data-str-error="{'Your vote could not be saved.'|translate|escape:'html'}">

  <span class="club-likes-title">{$club_likes_widget.TITLE|escape:'html'}</span>

{if $club_likes_widget.BUTTONS}
  <span class="club-likes-buttons">
{foreach from=$club_likes_widget.BUTTONS item=button key=level}
    <button type="button" data-role="none" class="club-likes-button club-likes-{$level}{if $club_likes_widget.LEVEL == $level} is-active{/if}"
      data-level="{$level}" aria-pressed="{if $club_likes_widget.LEVEL == $level}true{else}false{/if}"
      title="{$button.LABEL|escape:'html'}" aria-label="{$button.LABEL|escape:'html'}">{$button.ICON}</button>
{/foreach}
{if $club_likes_widget.UNDO_ICON}
    <button type="button" data-role="none" class="club-likes-button club-likes-undo" data-level="none"
      title="{'Remove my vote'|translate|escape:'html'}" aria-label="{'Remove my vote'|translate|escape:'html'}"{if $club_likes_widget.LEVEL == 'none'} hidden{/if}>{$club_likes_widget.UNDO_ICON}</button>
{/if}
  </span>
{/if}

  <span class="club-likes-counts">
    <span class="club-likes-count-likes">{$club_likes_widget.LIKES_TEXT}</span>
{if $club_likes_widget.SHOW_BRAVOS}
    <span class="club-likes-separator" aria-hidden="true">&middot;</span>
    <span class="club-likes-count-bravos">{$club_likes_widget.BRAVOS_TEXT}</span>
{/if}
  </span>

  <span class="club-likes-message" role="status" aria-live="polite"></span>
</div>
<script>
(function() {
  // move the widget into the photo information block of the theme, if it has one
  var widget = document.getElementById('clubLikes');
  var target = document.querySelector('#info-content, #standard, #PictureInfo, .imageInfoTable');
  if (!widget || !target || target.contains(widget)) return;
  if (/^(UL|OL)$/.test(target.tagName)) {
    var item = document.createElement('li');
    item.className = 'imageInfo';
    item.appendChild(widget);
    target.appendChild(item);
  }
  else {
    target.appendChild(widget);
  }
  widget.className += ' club-likes-in-info';
})();
</script>
