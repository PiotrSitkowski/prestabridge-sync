{**
 * configure.tpl — PrestaBridge Admin Configuration Page
 * Bootstrap 4 tabs: Configuration | Logs | Import Status (stub)
 *}
<div class="prestabridge-admin">

  {* Bootstrap 4 tabs navigation *}
  <ul class="nav nav-tabs" id="prestabridgeTabs" role="tablist">
    <li class="nav-item">
      <a class="nav-link active" id="tab-config-link" data-toggle="tab" href="#tab-config" role="tab"
         aria-controls="tab-config" aria-selected="true">
        <i class="icon-cog"></i> {l s='Configuration' mod='prestabridge'}
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="tab-logs-link" data-toggle="tab" href="#tab-logs" role="tab"
         aria-controls="tab-logs" aria-selected="false">
        <i class="icon-list"></i> {l s='Logs' mod='prestabridge'}
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="tab-status-link" data-toggle="tab" href="#tab-status" role="tab"
         aria-controls="tab-status" aria-selected="false">
        <i class="icon-bar-chart"></i> {l s='Import Status' mod='prestabridge'}
      </a>
    </li>
  </ul>

  <div class="tab-content mt-3" id="prestabridgeTabContent">

    {* === TAB: Configuration === *}
    <div class="tab-pane fade show active" id="tab-config" role="tabpanel" aria-labelledby="tab-config-link">
      {$config_form nofilter}
    </div>

    {* === TAB: Logs === *}
    <div class="tab-pane fade" id="tab-logs" role="tabpanel" aria-labelledby="tab-logs-link">
      {$logs_section nofilter}
    </div>

    {* === TAB: Import Status (stub — future PaaS monitoring) === *}
    <div class="tab-pane fade" id="tab-status" role="tabpanel" aria-labelledby="tab-status-link">
      <div class="alert alert-info mt-3">
        <i class="icon-info-sign"></i>
        {l s='Import Status monitoring will be available in a future version.' mod='prestabridge'}
      </div>
    </div>

  </div>{* /tab-content *}

  <hr class="mt-4"/>
  <p class="text-muted small">
    PrestaBridge v{$module_version|escape:'html':'UTF-8'}
  </p>

</div>{* /prestabridge-admin *}

<script>
  // Script removed to ensure first tab is always default
</script>
