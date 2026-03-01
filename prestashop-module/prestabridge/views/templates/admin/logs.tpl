{**
 * logs.tpl — PrestaBridge Admin Logs Table
 * Displays paginated BridgeLogger entries with level/source filtering.
 *}

{* --- Level Filter Form (GET) --- *}
<form method="get" action="{$pb_moduleLink|escape:'html':'UTF-8'}" class="form-inline mb-3">
  <input type="hidden" name="configure" value="prestabridge"/>

  <div class="form-group mr-2">
    <label for="pb_log_level" class="mr-1">{l s='Filter by level:' mod='prestabridge'}</label>
    <select id="pb_log_level" name="log_level" class="form-control form-control-sm">
      <option value="">{l s='All levels' mod='prestabridge'}</option>
      {foreach from=$pb_levels item=lvl}
        <option value="{$lvl|escape:'html':'UTF-8'}"
          {if $pb_currentLevel === $lvl}selected="selected"{/if}>
          {$lvl|ucfirst|escape:'html':'UTF-8'}
        </option>
      {/foreach}
    </select>
  </div>

  <button type="submit" class="btn btn-sm btn-secondary mr-3">
    <i class="icon-search"></i> {l s='Filter' mod='prestabridge'}
  </button>

  {* --- Clear Logs (separate POST form button) --- *}
  <form method="post" action="{$pb_moduleLink|escape:'html':'UTF-8'}" class="d-inline"
        onsubmit="return confirm('{l s='Are you sure you want to delete all logs?' mod='prestabridge'}');">
    <input type="hidden" name="clearLogs" value="1"/>
    <button type="submit" class="btn btn-sm btn-danger">
      <i class="icon-trash"></i> {l s='Clear all logs' mod='prestabridge'}
    </button>
  </form>
</form>

{* --- Summary --- *}
<p class="text-muted small">
  {l s='Total:' mod='prestabridge'} <strong>{$pb_total|intval}</strong>
  {l s='entries' mod='prestabridge'}
  {if $pb_totalPages > 1}
    &mdash; {l s='Page' mod='prestabridge'} {$pb_page|intval} / {$pb_totalPages|intval}
  {/if}
</p>

{* --- Log Table --- *}
{if $pb_logs && $pb_logs|count > 0}
  <div class="table-responsive">
    <table class="table table-sm table-hover table-bordered">
      <thead class="thead-light">
        <tr>
          <th style="white-space:nowrap">{l s='Date' mod='prestabridge'}</th>
          <th>{l s='Level' mod='prestabridge'}</th>
          <th>{l s='Source' mod='prestabridge'}</th>
          <th>{l s='Message' mod='prestabridge'}</th>
          <th>{l s='SKU' mod='prestabridge'}</th>
          <th>{l s='Context' mod='prestabridge'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$pb_logs item=log}
          <tr>
            <td style="white-space:nowrap;font-size:0.85em">
              {$log.created_at|escape:'html':'UTF-8'}
            </td>
            <td>
              {* Bootstrap badge color by level *}
              {if $log.level === 'critical'}
                <span class="badge badge-dark">{$log.level|escape:'html':'UTF-8'}</span>
              {elseif $log.level === 'error'}
                <span class="badge badge-danger">{$log.level|escape:'html':'UTF-8'}</span>
              {elseif $log.level === 'warning'}
                <span class="badge badge-warning">{$log.level|escape:'html':'UTF-8'}</span>
              {elseif $log.level === 'info'}
                <span class="badge badge-info">{$log.level|escape:'html':'UTF-8'}</span>
              {else}
                <span class="badge badge-secondary">{$log.level|escape:'html':'UTF-8'}</span>
              {/if}
            </td>
            <td>
              <code>{$log.source|escape:'html':'UTF-8'}</code>
            </td>
            <td>{$log.message|escape:'html':'UTF-8'}</td>
            <td>
              {if $log.sku}
                <code>{$log.sku|escape:'html':'UTF-8'}</code>
              {else}
                <span class="text-muted">&mdash;</span>
              {/if}
            </td>
            <td>
              {if $log.context && $log.context !== 'null' && $log.context !== '[]'}
                <details>
                  <summary class="text-muted small" style="cursor:pointer">
                    {l s='Show' mod='prestabridge'}
                  </summary>
                  <pre class="mt-1 p-1 bg-light" style="font-size:0.75em;max-width:400px;overflow:auto">{$log.context|escape:'html':'UTF-8'}</pre>
                </details>
              {else}
                <span class="text-muted">&mdash;</span>
              {/if}
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>

  {* --- Pagination --- *}
  {if $pb_totalPages > 1}
    <nav aria-label="{l s='Log pagination' mod='prestabridge'}">
      <ul class="pagination pagination-sm">
        {* Previous *}
        <li class="page-item {if $pb_page <= 1}disabled{/if}">
          <a class="page-link"
             href="{$pb_moduleLink|escape:'html':'UTF-8'}&amp;log_page={$pb_page|intval - 1}{if $pb_currentLevel}&amp;log_level={$pb_currentLevel|escape:'url'}{/if}">
            &laquo;
          </a>
        </li>

        {* Page numbers (±2 around current) *}
        {section name=p start=1 loop=$pb_totalPages+1 step=1}
          {assign var=pn value=$smarty.section.p.index}
          {if $pn >= $pb_page - 2 && $pn <= $pb_page + 2}
            <li class="page-item {if $pn === $pb_page}active{/if}">
              <a class="page-link"
                 href="{$pb_moduleLink|escape:'html':'UTF-8'}&amp;log_page={$pn}{if $pb_currentLevel}&amp;log_level={$pb_currentLevel|escape:'url'}{/if}">
                {$pn}
              </a>
            </li>
          {/if}
        {/section}

        {* Next *}
        <li class="page-item {if $pb_page >= $pb_totalPages}disabled{/if}">
          <a class="page-link"
             href="{$pb_moduleLink|escape:'html':'UTF-8'}&amp;log_page={$pb_page|intval + 1}{if $pb_currentLevel}&amp;log_level={$pb_currentLevel|escape:'url'}{/if}">
            &raquo;
          </a>
        </li>
      </ul>
    </nav>
  {/if}

{else}
  <div class="alert alert-info">
    <i class="icon-info-sign"></i>
    {l s='No log entries found.' mod='prestabridge'}
    {if $pb_currentLevel}
      <a href="{$pb_moduleLink|escape:'html':'UTF-8'}">{l s='Show all levels' mod='prestabridge'}</a>
    {/if}
  </div>
{/if}
