{**
 * views/templates/admin/configure.tpl
 * Strona konfiguracji modułu MG XML Feeds w panelu administracyjnym.
 * Pokazuje informacje ogólne, przyciski CRON, logi i listę feedów.
 *}

<div class="panel">
  <h3><i class="icon icon-rss"></i> {$module->displayName|escape:'html':'UTF-8'}</h3>

  <p>
    Ten moduł umożliwia generowanie <strong>plików XML z pełnymi danymi produktów</strong>,
    które mogą być wykorzystywane przez zewnętrzne aplikacje, integracje lub blogi WordPress.
  </p>

  <p>
    Każdy plik XML może być przygotowany wg zdefiniowanych filtrów (np. <em>kategorie</em>, <em>producenci</em>),
    oraz automatycznie odświeżany przez link CRON.
  </p>

  <hr>

  <div class="alert alert-info">
    <i class="icon-info-circle"></i>
    Ustawienia poszczególnych plików XML znajdziesz w sekcji
    <strong><a href="{$link->getAdminLink('AdminMgXmlFeeds')|escape:'html':'UTF-8'}">XML Feeds</a></strong>.
  </div>

  <h4><i class="icon-key"></i> Główny token CRON</h4>
  {assign var="master_token" value=Configuration::get($module::CONFIG_MASTER_TOKEN)}
  <div class="well">
    {if $master_token}
      {$master_token}
    {else}
      <em>Nie wygenerowano jeszcze tokenu – kliknij poniżej, aby go utworzyć.</em>
    {/if}
  </div>

  <form method="post" action="{$current|escape:'html':'UTF-8'}">
    <button type="submit" name="generateMasterToken" class="btn btn-default">
      <i class="icon-key"></i> Wygeneruj nowy token główny
    </button>
  </form>

  {if $master_token}
    <br>
    <h4><i class="icon-refresh"></i> CRON URL (wszystkie feedy)</h4>
    {assign var="cron_url_all" value=$link->getModuleLink('mgxmlfeeds', 'cron', ['all'=>1,'token'=>$master_token], true)}
    <div class="well">{$cron_url_all}</div>
  {/if}

  <hr>

  <h4><i class="icon-list"></i> Ostatnie logi generacji</h4>
  {assign var="logs" value=MgXmlFeedLog::getRecentByFeed(0,10)}
  {if $logs && count($logs)}
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>ID feedu</th>
          <th>Status</th>
          <th>Wiersze</th>
          <th>Czas [ms]</th>
          <th>Data</th>
          <th>Komunikat</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$logs item=log}
          <tr>
            <td>{$log.id_log}</td>
            <td>{$log.id_feed}</td>
            <td>
              {if $log.status == 'ok'}
                <span class="badge badge-mgxml ok">OK</span>
              {elseif $log.status == 'error'}
                <span class="badge badge-mgxml error">Błąd</span>
              {elseif $log.status == 'running'}
                <span class="badge badge-mgxml running">W toku</span>
              {else}
                <span class="badge badge-mgxml skipped">Pominięto</span>
              {/if}
            </td>
            <td>{$log.row_count|intval}</td>
            <td>{$log.build_time_ms|intval}</td>
            <td>{$log.finished_at|escape:'html':'UTF-8'}</td>
            <td>{$log.message|escape:'html':'UTF-8'}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {else}
    <p><em>Brak wpisów logów.</em></p>
  {/if}

  <hr>

  <h4><i class="icon-info-sign"></i> Lokalizacje plików XML</h4>
  <div class="mgxml-alert">
    Pliki XML są zapisywane w katalogu:
    <code>{$module->name}/var/cache/{id_feed}/</code><br>
    Każdy plik jest tworzony osobno dla języka i sklepu, np.:
    <code>feed-1-1.xml</code> lub <code>feed-1-1.xml.gz</code>
  </div>
</div>
