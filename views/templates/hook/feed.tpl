{**
 * views/templates/hook/feed.tpl
 * Publiczny podgląd XML feedu (debug / informacyjny)
 * Wyświetlany, gdy kontroler feed.php jest wywołany bez parametru ?download=1
 * lub gdy generacja XML jeszcze się nie odbyła.
 *}

<div class="container" style="max-width:960px; margin:40px auto; font-family:'Open Sans',Arial,sans-serif;">
  <h2 style="border-bottom:2px solid #ffd741; padding-bottom:6px;">
    <i class="icon-rss" style="color:#d90244;"></i> MG XML Feed — podgląd
  </h2>

  {if isset($feed) && $feed}
    <p>
      <strong>Nazwa feedu:</strong> {$feed.name|escape:'html':'UTF-8'}<br>
      <strong>ID feedu:</strong> {$feed.id_feed|intval}<br>
      <strong>Tryb:</strong>
      {if $feed.variant_mode == 'combination'}
        per combination
      {else}
        per product
      {/if}<br>
      <strong>Status:</strong>
      {if $feed.active}
        <span style="color:#2e7d32;font-weight:600;">aktywny</span>
      {else}
        <span style="color:#c62828;font-weight:600;">nieaktywny</span>
      {/if}<br>
      <strong>Ostatnia generacja:</strong>
      {if $feed.last_build_at}
        {$feed.last_build_at|escape:'html':'UTF-8'}
      {else}
        <em>nie była jeszcze uruchamiana</em>
      {/if}<br>
      <strong>Wiersze:</strong> {$feed.row_count|intval}<br>
      <strong>Czas generacji:</strong> {$feed.build_time_ms|intval} ms
    </p>

    <hr>

    <h4>Plik XML</h4>
    {assign var="filePath" value=sprintf('%s/var/cache/%d/%s-%d-%d.xml',$module->name,$feed.id_feed,$feed.file_basename,$feed.id_lang|default:1,$feed.id_shop|default:1)}
    {assign var="xmlUrl" value=$link->getModuleLink('mgxmlfeeds','feed',['id'=>$feed.id_feed,'download'=>1],true)}

    {if file_exists($module->getLocalPath()|cat:'var/cache/'|cat:$feed.id_feed|cat:'/'|cat:$feed.file_basename|cat:'-1-1.xml')}
      <p>
        <strong>Ścieżka:</strong> <code>{$filePath}</code><br>
        <strong>Link do pliku XML:</strong>
        <a href="{$xmlUrl|escape}" target="_blank">{$xmlUrl}</a>
      </p>
      <p>
        <a class="btn btn-default" style="background:#ffd741;color:#2c2c2c;font-weight:600;" href="{$xmlUrl|escape}" target="_blank">
          <i class="icon-download"></i> Pobierz XML
        </a>
        <a class="btn btn-default" style="margin-left:8px;" target="_blank"
           href="{$link->getModuleLink('mgxmlfeeds','cron',['id'=>$feed.id_feed,'token'=>$feed.cron_token],true)|escape}">
          <i class="icon-refresh"></i> Wygeneruj ponownie
        </a>
      </p>
    {else}
      <div style="background:#fff9e6;border:1px solid #ffe08a;padding:8px 10px;border-radius:3px;">
        <i class="icon-warning-sign" style="color:#c77f00;"></i>
        Nie znaleziono jeszcze wygenerowanego pliku XML dla tego feedu.<br>
        <small>Aby go utworzyć, uruchom CRON lub kliknij "Wygeneruj ponownie".</small>
      </div>
    {/if}

    <hr>

    <h4>Token CRON dla tego feedu</h4>
    <div style="background:#f7f7f7;border:1px solid #e0e0e0;padding:6px 10px;border-radius:3px;word-break:break-all;">
      {$feed.cron_token|escape:'html':'UTF-8'}
    </div>

    <p style="margin-top:10px;">
      <strong>URL CRON:</strong><br>
      <code>{$link->getModuleLink('mgxmlfeeds','cron',['id'=>$feed.id_feed,'token'=>$feed.cron_token],true)|escape}</code>
    </p>

    <hr>

    <p style="font-size:13px;color:#777;">
      <em>Ten widok służy wyłącznie do podglądu. Pełny plik XML jest generowany
      i udostępniany automatycznie przez CRON.</em>
    </p>
  {else}
    <div style="background:#fff3f3;border:1px solid #f0b5b5;padding:10px;border-radius:3px;color:#a33;">
      <i class="icon-warning-sign"></i>
      Nie znaleziono informacji o feedzie. Upewnij się, że przekazano prawidłowy parametr <code>id</code>.
    </div>
  {/if}
</div>
