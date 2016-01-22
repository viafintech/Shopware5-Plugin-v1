{block name="frontend_index_content_top"}

{if $barzahlen_payment_error}
<div class="grid_20 first">
  {* Step box *}
  {include file="frontend/register/steps.tpl" sStepActive="finished"}

  <div class="error agb_confirm">
    <div class="center">
      <strong>
        {$barzahlen_payment_error}
      </strong>
    </div>
  </div>
</div>
{/if}

{/block}