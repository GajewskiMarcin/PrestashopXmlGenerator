{**
 * views/templates/admin/list.tpl
 * Lista feedów XML z akcjami (edytuj, usuń, generuj, regeneruj token).
 * Szablon niezależny od HelperList – jeżeli w kontrolerze używasz HelperList,
 * ten plik może służyć jako alternatywny widok lub podgląd.
 *}

<div class="panel">
  <h3><i class="icon icon-rss"></i> {$module->displayName|escape:'html':'UTF-8'} — Feedy</h3>

  {assign var=admin_url value=$link->getAdminLink('AdminMgXmlFeeds')}
  {assign var=master_token value=Configuration::get($module::CONFIG_MASTER_TOKEN)}
  {assign var=cron_all_url value=$link->getModuleLink('mgxmlfeeds','cron',['all'=>1,'token'=>$master_token], true)}

  <div class="clearfix" style="margin-bottom:10px;">
    <a class="btn btn-primary pull-left" href="{$admin_url|escape}&addmgxmlfeed=1">
      <i class="icon-plus"></i> {l s='Add feed' mod='mgxmlfeeds'}
    </a>
    {if $master_token}
      <a class="btn btn-default pull-right" target="_blank" href="{$cron_all_url|escape}">
        <i class="icon-refresh"></i> {l s='Run CRON (all)' mod='mgxmlfeeds'}
      </a>
    {else}
      <span class="label label-warning pull-right">
        <i class="icon-warning-sign"></i> {l s='Generate master token in module configuration to use CRON (all).' mod='mgxmlfeeds'}
      </span>
    {/if}
  </div>

  {if isset($feeds) && $feeds|@count}
    <table class="table">
      <thead>
        <tr>
          <th class="text-center" style="width:60px;">ID</th>
          <th class="text-center" style="width:80px;">{l s='Active' mod='mgxmlfeeds'}</th>
          <th>{l s='Name' mod='mgxmlfeeds'}</th>
          <th>{l s='Basename' mod='mgxmlfeeds'}</th>
          <th class="text-center" style="width:140px;">{l s='Mode' mod='mgxmlfeeds'}</th>
          <th class="text-center" style="width:90px;">TTL</th>
          <th style="width:170px;">{l s='Last build' mod='mgxmlfeeds'}</th>
          <th class="text-center" style="width:110px;">{l s='Status' mod='mgxmlfeeds'}</th>
          <th class="text-center" style="width:90px;">{l s='Rows' mod='mgxmlfeeds'}</th>
          <th class="text-center" style="width:110px;">{l s='Time [ms]' mod='mgxmlfeeds'}</th>
          <th style="width:320px;" class="text-right">{l s='Actions' mod='mgxmlfeeds'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$feeds item=feed}
          {assign var=cron_url value=$link->getModuleLink('mgxmlfeeds','cron',['id'=>$feed.id_feed,'token'=>$feed.cron_token], true)}
          {assign var=serve_url value=$link->getModuleLink('mgxmlfeeds','feed',['id'=>$feed.id_feed], true)}
          <tr>
            <td class="text-center">{$feed.id_feed|intval}</td>
            <td class="text-center">
              {if $feed.active}
                <span class="label label-success">{l s='Enabled' mod='mgxmlfeeds'}</span>
              {else}
                <span class="label label-danger">{l s='Disabled' mod='mgxmlfeeds'}</span>
              {/if}
            </td>
            <td>{$feed.name|escape:'html':'UTF-8'}</td>
            <td><code>{$feed.file_basename|escape:'html':'UTF-8'}</code></td>
            <td class="text-center">
              {if $feed.variant_mode == 'combination'}
                <span class="badge badge-mgxml running">{l s='Per combination' mod='mgxmlfeeds'}</span>
              {else}
                <span class="badge badge-mgxml ok">{l s='Per product' mod='mgxmlfeeds'}</span>
              {/if}
            </td>
            <td class="text-center">{$feed.ttl_minutes|intval} min</td>
            <td>{$feed.last_build_at|escape:'html':'UTF-8'}</td>
            <td class="text-center">
              {if $feed.last_status == 'ok'}
                <span class="badge badge-mgxml ok">OK</span>
              {elseif $feed.last_status == 'error'}
                <span class="badge badge-mgxml error">{l s='Error' mod='mgxmlfeeds'}</span>
              {elseif $feed.last_status == 'running'}
                <span class="badge badge-mgxml running">{l s='Running' mod='mgxmlfeeds'}</span>
              {elseif $feed.last_status == 'skipped'}
                <span class="badge badge-mgxml skipped">{l s='Skipped' mod='mgxmlfeeds'}</span>
              {else}
                <span class="label label-default">—</span>
              {/if}
            </td>
            <td class="text-center">{$feed.row_count|intval}</td>
            <td class="text-center">{$feed.build_time_ms|intval}</td>
            <td class="text-right">
              <a class="btn btn-default" target="_blank" href="{$serve_url|escape}">
                <i class="icon-eye-open"></i> {l s='View' mod='mgxmlfeeds'}
              </a>
              <a class="btn btn-default" target="_blank" href="{$cron_url|escape}">
                <i class="icon-refresh"></i> {l s='Build' mod='mgxmlfeeds'}
              </a>
              <a class="btn btn-default" href="{$admin_url|escape}&regenToken=1&id_feed={$feed.id_feed|intval}">
                <i class="icon-key"></i> {l s='Regenerate token' mod='mgxmlfeeds'}
              </a>
              <a class="btn btn-warning" href="{$admin_url|escape}&updatemgxmlfeed&id_mgxmlfeed={$feed.id_feed|intval}">
                <i class="icon-pencil"></i> {l s='Edit' mod='mgxmlfeeds'}
              </a>
              <a class="btn btn-danger" href="{$admin_url|escape}&deletemgxmlfeed&id_mgxmlfeed={$feed.id_feed|intval}"
                 onclick="return confirm('{l s='Delete this feed?' mod='mgxmlfeeds' js=1}');">
                <i class="icon-trash"></i> {l s='Delete' mod='mgxmlfeeds'}
              </a>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <div class="alert alert-warning">
      <i class="icon-info-sign"></i>
      {l s='No feeds created yet. Click "Add feed" to create your first XML feed.' mod='mgxmlfeeds'}
    </div>
  {/if}
</div>
