{combine_css path=$like_bravo_widget.CSS_PATH}
{if $like_bravo_widget.BUTTONS}
{combine_script id='like_bravo' load='footer' path=$like_bravo_widget.JS_PATH}
{/if}

<div id="likeBravo" class="like-bravo"
  data-image-id="{$like_bravo_widget.IMAGE_ID}"
  data-level="{$like_bravo_widget.LEVEL}"
  data-url="{$like_bravo_widget.WS_URL|escape:'html'}"
  data-token="{$like_bravo_widget.PWG_TOKEN}"
  data-str-like="{'%d like'|translate|escape:'html'}"
  data-str-likes="{'%d likes'|translate|escape:'html'}"
  data-str-bravo="{'%d bravo'|translate|escape:'html'}"
  data-str-bravos="{'%d bravos'|translate|escape:'html'}"
  data-str-error="{'Your vote could not be saved.'|translate|escape:'html'}">

  <span class="like-bravo-title">{$like_bravo_widget.TITLE|escape:'html'}</span>

{if $like_bravo_widget.BUTTONS}
  <span class="like-bravo-buttons">
{foreach from=$like_bravo_widget.BUTTONS item=button key=level}
    <button type="button" data-role="none" class="like-bravo-button like-bravo-{$level}{if $like_bravo_widget.LEVEL == $level} is-active{/if}"
      data-level="{$level}" aria-pressed="{if $like_bravo_widget.LEVEL == $level}true{else}false{/if}"
      title="{$button.LABEL|escape:'html'}" aria-label="{$button.LABEL|escape:'html'}">{$button.ICON}</button>
{/foreach}
{if $like_bravo_widget.UNDO_ICON}
    <button type="button" data-role="none" class="like-bravo-button like-bravo-undo" data-level="none"
      title="{'Remove my vote'|translate|escape:'html'}" aria-label="{'Remove my vote'|translate|escape:'html'}"{if $like_bravo_widget.LEVEL == 'none'} hidden{/if}>{$like_bravo_widget.UNDO_ICON}</button>
{/if}
  </span>
{/if}

  <span class="like-bravo-counts">
    <span class="like-bravo-count-likes">{$like_bravo_widget.LIKES_TEXT}</span>
{if $like_bravo_widget.SHOW_BRAVOS}
    <span class="like-bravo-separator" aria-hidden="true">&middot;</span>
    <span class="like-bravo-count-bravos">{$like_bravo_widget.BRAVOS_TEXT}</span>
{/if}
  </span>

  <span class="like-bravo-message" role="status" aria-live="polite"></span>
</div>
<script>
(function() {
  // move the widget into the photo information block of the theme, if it has one
  var widget = document.getElementById('likeBravo');
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
  widget.className += ' like-bravo-in-info';
})();
</script>
