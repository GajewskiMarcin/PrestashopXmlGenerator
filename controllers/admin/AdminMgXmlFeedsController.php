<?php
/**
 * AdminMgXmlFeedsController – BO (legacy) dla mgxmlfeeds
 * PS 1.7/8/9 – ModuleAdminController
 *
 * Funkcje:
 * - Lista feedów (akcje: Edit/Delete/Build/Regenerate token)
 * - Formularz „wyklikiwany”: kategorie (drzewo), producenci (multi-select),
 *   przełączniki aktywności/stanów/min-max ceny, języki (checkboxy ISO),
 *   waluty (multi-select), mapowanie pól (tabela z checkbox+alias) + hidden JSON
 * - Build w BO: najpierw próba FeedBuilderService->FeedExporter,
 *   a jeśli brak klas – fallback przez CRON (front lub plik), a na końcu lokalny include.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminMgXmlFeedsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap   = true;
        // UWAGA: nazwa tabeli musi odpowiadać ObjectModel MgXmlFeed::$definition['table']
        $this->table       = 'mgxmlfeed'; // << ważne: bez podkreśleń, zgodnie z Twoją tabelą ps_mgxmlfeed
        $this->className   = 'MgXmlFeed';
        $this->identifier  = 'id_feed';
        $this->lang        = false;
        $this->list_no_link = true;

        parent::__construct();

        // Bezpieczne dołączanie klas – nie wysypie BO, jeśli pliku chwilowo brak
        $moduleDir = _PS_MODULE_DIR_.'mgxmlfeeds/';
        $try = function ($p) { if (is_file($p)) require_once $p; };
        $try($moduleDir.'classes/MgXmlFeed.php');
        $try($moduleDir.'classes/MgXmlFeedLog.php');
        $try($moduleDir.'classes/FeedBuilderService.php');
        $try($moduleDir.'classes/FeedExporter.php');

        // LISTA – bazowe kolumny (upewnij się, że istnieją w ps_mgxmlfeed)
        $this->fields_list = [
            'id_feed' => [
                'title' => $this->l('ID'),
                'class' => 'fixed-width-xs text-center',
                'align' => 'text-center',
            ],
            'name' => [ 'title' => $this->l('Name') ],

            // ZAMIENIA surową kolumnę 'token'
            'token_display' => [
                'title'    => $this->l('Token'),
                'callback' => 'renderTokenCell',
                'search'   => false,
                'orderby'  => false,
            ],

            'last_build_at' => [ 'title' => $this->l('Last build at') ],
            'row_count'     => [ 'title' => $this->l('Rows'), 'align' => 'text-right', 'class' => 'fixed-width-sm' ],

            // Linki do feeda (Preview/Download) z przyciskami „Kopiuj”
            'feed_urls' => [
                'title'    => $this->l('Feed URLs'),
                'callback' => 'renderFeedLinks',
                'search'   => false,
                'orderby'  => false,
                'remove_onclick' => true,
            ],
        ];


        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
            ],
        ];

        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->addRowAction('build');
        $this->addRowAction('regenerateToken');
    }

    /* === RENDERER KOLUMNY Z LINKAMI FEEDA === */
    public function renderFeedLinks($value, $row)
    {
        $id  = (int)$row['id_feed'];
        if (!$id) return '<span class="text-muted">—</span>';

        $feed = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($feed)) return '<span class="text-muted">—</span>';

        $idShop = property_exists($feed,'id_shop') ? (int)$feed->id_shop : (int)$this->context->shop->id;

        $token = (property_exists($feed,'token') && !empty($feed->token)) ? $feed->token : '';
        if ($token === '' && property_exists($feed,'cron_token') && !empty($feed->cron_token)) {
            $token = $feed->cron_token;
        }
        if ($token === '') return '<span class="text-muted">—</span>';

        $link = $this->context->link;
        $preview  = $link->getModuleLink('mgxmlfeeds','feed',[ 'id_feed'=>$id,'token'=>$token,'download'=>0 ], null, null, $idShop);
        $download = $link->getModuleLink('mgxmlfeeds','feed',[ 'id_feed'=>$id,'token'=>$token,'download'=>1 ], null, null, $idShop);

        $p = htmlspecialchars($preview,  ENT_QUOTES, 'UTF-8');
        $d = htmlspecialchars($download, ENT_QUOTES, 'UTF-8');

        return
        '<a target="_blank" href="'.$p.'">'.$this->l('Preview').'</a> '
        . '<button type="button" class="btn btn-xs btn-default js-copy" data-copy="'.$p.'">'.$this->l('Copy').'</button>'
        . ' &nbsp;|&nbsp; '
        . '<a target="_blank" href="'.$d.'">'.$this->l('Download').'</a> '
        . '<button type="button" class="btn btn-xs btn-default js-copy" data-copy="'.$d.'">'.$this->l('Copy').'</button>';
    }

    protected function clipboardJs()
    {
        return '<script>(function(){if(window.__mg_copy_init__)return;window.__mg_copy_init__=true;
    document.addEventListener("click",function(e){var b=e.target.closest(".js-copy");if(!b)return;
    var t=b.getAttribute("data-copy")||"";if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t);}
    else{var ta=document.createElement("textarea");ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand("copy");}catch(e){}document.body.removeChild(ta);}
    b.classList.add("btn-success");setTimeout(function(){b.classList.remove("btn-success");},600);});})();</script>';
    }

    public function renderList()
    {
        return parent::renderList().$this->clipboardJs();
    }


    public function renderTokenCell($value, $row)
    {
        $id = (int)$row['id_feed'];
        if (!$id) return '<span class="text-muted">—</span>';

        $feed = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($feed)) return '<span class="text-muted">—</span>';

        $token = (property_exists($feed,'token') && !empty($feed->token)) ? $feed->token : '';
        if ($token === '' && property_exists($feed,'cron_token') && !empty($feed->cron_token)) {
            $token = $feed->cron_token;
        }

        if ($token === '') {
            // brak – pokaż przycisk do wygenerowania
            $href = self::$currentIndex.'&'.$this->identifier.'='.$id.'&regenerateToken=1&type='
                . (property_exists($feed,'token') ? 'feed' : 'cron')
                .'&token='.$this->token;
            return '<a class="btn btn-xs btn-default" href="'.htmlspecialchars($href).'">'
                . '<i class="icon-refresh"></i> '.$this->l('Generate').'</a>';
        }

        $t = htmlspecialchars($token);
        return '<code>'.$t.'</code> '
            . '<button type="button" class="btn btn-xs btn-default js-copy" data-copy="'.$t.'">'
            . '<i class="icon-copy"></i> '.$this->l('Copy').'</button>';
    }


    /* === AKCJE WIERSZA === */
    public function displayBuildLink($token = null, $id = 0, $name = null)
    {
        $href = self::$currentIndex.'&'.$this->identifier.'='.(int)$id.'&build'.$this->table.'=1&token='.$this->token;
        return '<a class="btn btn-default" href="'.htmlspecialchars($href).'">'
            .'<i class="icon-cogs"></i> '.$this->l('Build').'</a>';
    }

    public function displayRegenerateTokenLink($token = null, $id = 0, $name = null)
    {
        $href = self::$currentIndex.'&'.$this->identifier.'='.(int)$id.'&regenerateToken=1&token='.$this->token;
        return '<a class="btn btn-default" href="'.htmlspecialchars($href).'">'
            .'<i class="icon-refresh"></i> '.$this->l('Regenerate token').'</a>';
    }

    public function postProcess()
    {
        // Build
        if (Tools::getIsset('build'.$this->table)) {
            return $this->processBuild();
        }
        // Token
        if (Tools::getIsset('regenerateToken')) {
            return $this->processRegenerateToken();
        }
        return parent::postProcess();
    }

    protected function processBuild()
    {
        $id = (int)Tools::getValue($this->identifier);
        if ($id <= 0) {
            return false;
        }
        $feed = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($feed)) {
            $this->errors[] = $this->l('Feed not found.');
            return false;
        }
        try {
            $result = $this->runFeedBuild($feed);
            if ($result['status'] !== 'ok') {
                $this->errors[] = $result['message'];
            } else {
                $this->confirmations[] = sprintf($this->l('Feed built. Rows: %d, time: %d ms'), (int)$result['rows'], (int)$result['time_ms']);
            }
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
        }
        return true;
    }

    protected function processRegenerateToken()
    {
        $id = (int)Tools::getValue($this->identifier);
        if ($id <= 0) {
            return false;
        }
        $feed = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($feed)) {
            $this->errors[] = $this->l('Feed not found.');
            return false;
        }
        $which = Tools::getValue('type'); // 'feed' => token, 'cron' => cron_token
        $new = Tools::strtolower(Tools::passwdGen(32));

        if ($which === 'cron' && property_exists($feed, 'cron_token')) {
            $feed->cron_token = $new;
        } elseif ($which === 'feed' && property_exists($feed, 'token')) {
            $feed->token = $new;
        } else {
            if (property_exists($feed, 'token'))      { $feed->token = $new; }
            elseif (property_exists($feed, 'cron_token')) { $feed->cron_token = $new; }
            else { $this->errors[] = $this->l('No token field found on model.'); return false; }
        }

        if (!$feed->update()) {
            $this->errors[] = $this->l('Could not regenerate token.');
            return false;
        }
        $this->confirmations[] = $this->l('Token regenerated.');
        return true;
    }

    /* === HTML bloki: tokeny i URL-e === */
    protected function renderTokenBlockHtml($obj)
    {
        $id = (int)$obj->id;
        $baseLink = self::$currentIndex.'&'.$this->identifier.'='.$id.'&token='.$this->token;
        $hasFeedToken = property_exists($obj,'token') && !empty($obj->token);
        $hasCronToken = property_exists($obj,'cron_token') && !empty($obj->cron_token);
        $safe = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $html = '<div class="well" style="margin-bottom:0">';
        if ($hasFeedToken) {
            $regen = $baseLink.'&regenerateToken=1&type=feed';
            $html .= '<div><strong>'.$this->l('Feed token').':</strong> <code>'.$safe($obj->token).'</code> '
                . '<button type="button" class="btn btn-xs btn-default js-copy" data-copy="'.$safe($obj->token).'"><i class="icon-copy"></i> '.$this->l('Copy').'</button> '
                . '<a class="btn btn-xs btn-default" href="'.$safe($regen).'"><i class="icon-refresh"></i> '.$this->l('Regenerate').'</a></div>';
        }
        if ($hasCronToken) {
            $regen = $baseLink.'&regenerateToken=1&type=cron';
            $html .= '<div style="margin-top:6px"><strong>'.$this->l('Cron token').':</strong> <code>'.$safe($obj->cron_token).'</code> '
                . '<button type="button" class="btn btn-xs btn-default js-copy" data-copy="'.$safe($obj->cron_token).'"><i class="icon-copy"></i> '.$this->l('Copy').'</button> '
                . '<a class="btn btn-xs btn-default" href="'.$safe($regen).'"><i class="icon-refresh"></i> '.$this->l('Regenerate').'</a></div>';
        }
        if (!$hasFeedToken && !$hasCronToken) {
            $html .= '<div class="text-warning">'.$this->l('No token fields on model').'</div>';
        }
        $html .= '</div>';
        $html .= $this->clipboardJs();
        return $html;
    }

    protected function renderFeedUrlsHtml($obj)
    {
        $id = (int)$obj->id; $idShop = (int)$obj->id_shop;
        $tokenFeed = property_exists($obj,'token') && $obj->token ? (string)$obj->token : (property_exists($obj,'cron_token') ? (string)$obj->cron_token : '');
        if (!$id || !$tokenFeed) return '<span class="text-muted">'.$this->l('Save the feed and generate token first').'</span>';
        $link = $this->context->link;
        $preview = $link->getModuleLink('mgxmlfeeds','feed',[ 'id_feed'=>$id, 'token'=>$tokenFeed, 'download'=>0 ], null, null, $idShop);
        $download= $link->getModuleLink('mgxmlfeeds','feed',[ 'id_feed'=>$id, 'token'=>$tokenFeed, 'download'=>1 ], null, null, $idShop);
        $s = function($u){ return htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); };
        $html = '<div class="well" style="margin-bottom:0">'
              . '<div><strong>'.$this->l('Preview').':</strong> <a target="_blank" href="'.$s($preview).'">'.$s($preview).'</a></div>'
              . '<div style="margin-top:6px"><strong>'.$this->l('Download').':</strong> <a target="_blank" href="'.$s($download).'">'.$s($download).'</a></div>'
              . '</div>';
        return $html;
    }

    protected function renderCronUrlHtml($obj)
    {
        $id = (int)$obj->id; $idShop = (int)$obj->id_shop;
        $tokenCron = property_exists($obj,'cron_token') ? (string)$obj->cron_token : '';
        if (!$id) return '<span class="text-muted">'.$this->l('Save first').'</span>';
        $link = $this->context->link;
        $front = $link->getModuleLink('mgxmlfeeds','cron',[ 'id_feed'=>$id, 'cron_token'=>$tokenCron ], null, null, $idShop);
        $file  = Tools::getShopDomainSsl(true).__PS_BASE_URI__.'modules/mgxmlfeeds/cron.php?id_feed='.$id.'&cron_token='.rawurlencode($tokenCron);
        $s = function($u){ return htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); };
        $html = '<div class="well" style="margin-bottom:0">'
              . '<div><strong>'.$this->l('Front controller').':</strong> <a target="_blank" href="'.$s($front).'">'.$s($front).'</a></div>'
              . '<div style="margin-top:6px"><strong>'.$this->l('File fallback').':</strong> <a target="_blank" href="'.$s($file).'">'.$s($file).'</a></div>'
              . '</div>';
        return $html;
    }

    /* === FORMULARZ === */
    public function renderForm()
    {
        if (!($obj = $this->loadObject(true))) {
            return '';
        }

        // Odczyt obecnych JSONów → tablice
        $filters    = $this->jsonDecodeSafe($obj->filters, []);
        $languages  = $this->jsonDecodeSafe($obj->languages, []); // np. ["pl","en"] – ISO
        $currencies = $this->jsonDecodeSafe($obj->currencies, []); // np. ["PLN","EUR"] – ISO
        $fieldMap   = $this->jsonDecodeSafe($obj->field_map, []);

        // Kategorie – drzewo checkboxów
        $selectedCatIds   = array_map('intval', (array)($filters['categories'] ?? []));
        $includeChildren  = !empty($filters['include_children']);
        $tree = new HelperTreeCategories('mgxmlfeeds_categories');
        $tree->setRootCategory((int)Configuration::get('PS_ROOT_CATEGORY'));
        $tree->setUseCheckBox(true);
        $tree->setSelectedCategories($selectedCatIds);
        $treeHtml = $tree->render();

        // Producenci – select multiple
        $mans = Manufacturer::getManufacturers(false, $this->context->language->id, true);
        $manufacturerOptions = [];
        foreach ($mans as $m) {
            $manufacturerOptions[] = [
                'id_option' => (int)$m['id_manufacturer'],
                'name'      => $m['name'],
            ];
        }
        $selectedMans = array_map('intval', (array)($filters['manufacturers'] ?? []));

        // Języki – checkboxy (ISO)
        $langs = Language::getLanguages(true, $this->context->shop->id);
        $langCheckbox = [];
        foreach ($langs as $l) {
            $langCheckbox[] = [
                'id'   => (int)$l['id_lang'],
                'iso'  => $l['iso_code'],
                'name' => sprintf('%s (%s)', $l['name'], $l['iso_code']),
                'checked' => in_array($l['iso_code'], $languages, true),
            ];
        }

        // Waluty – select multiple (ISO) – obiekty *lub* tablice
        $currs = Currency::getCurrencies(true, true, true);
        $currencyOptions = [];
        $selectedCurrencyIds = [];
        foreach ($currs as $c) {
            if ($c instanceof Currency) {
                $idCurrency = (int)($c->id ?: $c->id_currency);
                $iso        = (string)$c->iso_code;
                $name       = (string)$c->name;
            } elseif (is_array($c)) {
                $idCurrency = (int)$c['id_currency'];
                $iso        = (string)$c['iso_code'];
                $name       = (string)$c['name'];
            } else {
                continue;
            }
            $currencyOptions[] = [
                'id_option' => $idCurrency,
                'iso'       => $iso,
                'name'      => sprintf('%s (%s)', $name, $iso),
            ];
            if (in_array($iso, $currencies, true)) {
                $selectedCurrencyIds[] = $idCurrency;
            }
        }

        // Mapowanie pól – z predefiniowaną listą
        $mapKeys = $this->getFieldMapKeys();
        $mapRows = [];
        foreach ($mapKeys as $k) {
            $val = $fieldMap[$k] ?? false; // true|false|alias
            $enabled = ($val !== false);
            $alias   = ($val !== true && $val !== false) ? (string)$val : '';
            $mapRows[] = [ 'key' => $k, 'enabled' => $enabled, 'alias' => $alias ];
        }

        // Tokeny + URL-e (HTML)
        $tokensHtml   = $this->renderTokenBlockHtml($obj);
        $feedUrlsHtml = $this->renderFeedUrlsHtml($obj);
        $cronUrlHtml  = $this->renderCronUrlHtml($obj);

        // Definicja formularza
        $this->fields_form = [
            'legend' => [
                'title' => $this->l('XML Feed'),
                'icon'  => 'icon-cogs',
            ],
            'input'  => [
                [
                    'type'  => 'text',
                    'label' => $this->l('Name'),
                    'name'  => 'name',
                    'required' => true,
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('File basename'),
                    'name'  => 'file_basename',
                    'required' => true,
                    'desc' => $this->l('Base filename without extension (letters, digits, dashes/underscores). Example: products-feed'),
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Tokens'),
                    'name'  => 'tokens_html',
                    'html_content' => $tokensHtml,
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Feed URLs'),
                    'name'  => 'feed_urls_html',
                    'html_content' => $feedUrlsHtml,
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Cron URL'),
                    'name'  => 'cron_url_html',
                    'html_content' => $cronUrlHtml,
                ],
                // --- FILTRY ---
                [
                    'type'  => 'html',
                    'label' => $this->l('Categories'),
                    'name'  => 'categories_tree_html',
                    'html_content' => $treeHtml,
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Include subcategories'),
                    'name' => 'filters_include_children',
                    'is_bool' => true,
                    'values' => [
                        [ 'id' => 'on',  'value' => 1, 'label' => $this->l('Yes') ],
                        [ 'id' => 'off', 'value' => 0, 'label' => $this->l('No')  ],
                    ],
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Manufacturers'),
                    'name' => 'filters_manufacturers[]',
                    'multiple' => true,
                    'options' => [
                        'query' => $manufacturerOptions,
                        'id'    => 'id_option',
                        'name'  => 'name',
                    ],
                    'desc' => $this->l('Leave empty to include all'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Only active products'),
                    'name' => 'filters_only_active',
                    'is_bool' => true,
                    'values' => [
                        [ 'id' => 'on',  'value' => 1, 'label' => $this->l('Yes') ],
                        [ 'id' => 'off', 'value' => 0, 'label' => $this->l('No')  ],
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Only available in stock'),
                    'name' => 'filters_only_available',
                    'is_bool' => true,
                    'values' => [
                        [ 'id' => 'on',  'value' => 1, 'label' => $this->l('Yes') ],
                        [ 'id' => 'off', 'value' => 0, 'label' => $this->l('No')  ],
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Min price'),
                    'name' => 'filters_min_price',
                    'suffix' => $this->l('leave empty = no limit'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Max price'),
                    'name' => 'filters_max_price',
                    'suffix' => $this->l('leave empty = no limit'),
                ],

                // --- JĘZYKI & WALUTY ---
                [
                    'type' => 'html',
                    'label' => $this->l('Languages'),
                    'name'  => 'languages_checkboxes_html',
                    'html_content' => $this->renderLangCheckboxesHtml($langCheckbox),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Currencies'),
                    'name' => 'currencies_select[]',
                    'multiple' => true,
                    'options' => [
                        'query' => $currencyOptions,
                        'id'    => 'id_option',
                        'name'  => 'name',
                    ],
                ],

                // --- MAPOWANIE PÓL ---
                [
                    'type' => 'html',
                    'label' => $this->l('Field map'),
                    'name'  => 'field_map_table_html',
                    'html_content' => $this->renderFieldMapTableHtml($mapRows),
                ],

                // --- HIDDENY Z JSON (składane w JS przy submit) ---
                [ 'type' => 'hidden', 'name' => 'filters_json' ],
                [ 'type' => 'hidden', 'name' => 'languages_json' ],
                [ 'type' => 'hidden', 'name' => 'currencies_json' ],
                [ 'type' => 'hidden', 'name' => 'field_map_json' ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
            ],
        ];

        // Wartości formularza
        $this->fields_value = [
            'name' => $obj->name,
            'file_basename' => isset($obj->file_basename) ? $obj->file_basename : '',
            'filters_include_children' => (int)$includeChildren,
            'filters_manufacturers[]'  => $selectedMans,
            'filters_only_active'      => (int)($filters['only_active'] ?? 0),
            'filters_only_available'   => (int)($filters['only_available'] ?? 0),
            'filters_min_price'        => isset($filters['min_price']) ? (float)$filters['min_price'] : '',
            'filters_max_price'        => isset($filters['max_price']) ? (float)$filters['max_price'] : '',
            // select walut: ID walut wybrane na podstawie ISO z JSON
            'currencies_select[]'      => $selectedCurrencyIds,
            // hiddeny inicjalnie z obecnych JSONów
            'filters_json'    => json_encode($filters, JSON_UNESCAPED_UNICODE),
            'languages_json'  => json_encode($languages, JSON_UNESCAPED_UNICODE),
            'currencies_json' => json_encode($currencies, JSON_UNESCAPED_UNICODE),
            'field_map_json'  => json_encode($fieldMap, JSON_UNESCAPED_UNICODE),
        ];

        // Render + JS składający JSON
        $formHtml = parent::renderForm();
        $formHtml .= $this->renderComposerJs();
        return $formHtml;
    }

    public function processSave()
    {
        // Złóż JSONy przed zapisem do ObjectModel
        $this->composeJsonFromPostedUi();

        $isAdd = ((int)Tools::getValue($this->identifier) <= 0);

        // Autogeneracja tokenów przy dodawaniu
        if ($isAdd) {
            if (!Tools::getValue('token')) {
                $_POST['token'] = Tools::strtolower(Tools::passwdGen(32));
            }
            if (!Tools::getValue('cron_token')) {
                $_POST['cron_token'] = Tools::strtolower(Tools::passwdGen(32));
            }
        }

        // Uzupełnij file_basename jeśli puste – slug z nazwy lub 'feed'
        $basename = (string)Tools::getValue('file_basename');
        if ($basename === '') {
            $name = (string)Tools::getValue('name');
            // spróbuj zrobić slug
            if (method_exists('Tools','link_rewrite')) {
                $basename = Tools::link_rewrite($name);
            }
            if (!$basename) {
                // alternatywny slug
                $basename = trim(preg_replace('~[^a-z0-9_-]+~i','-', Tools::substr($name, 0, 64)), '-');
            }
            if ($basename === '') {
                $basename = 'feed';
            }
            $_POST['file_basename'] = Tools::strtolower($basename);
        } else {
            // sanity check – oczyść znaki
            $clean = trim(preg_replace('~[^a-z0-9_-]+~i','-', Tools::substr($basename, 0, 64)), '-');
            if ($clean === '') { $clean = 'feed'; }
            $_POST['file_basename'] = Tools::strtolower($clean);
        }

        return parent::processSave();
    }

    public function processUpdate()
    {
        // Zapewniamy, że przed update'em złożone zostaną JSONy z formularza
        $this->composeJsonFromPostedUi();

        return parent::processUpdate();
    }

    /* === POMOCNICZE === */
    protected function jsonDecodeSafe($json, $default)
    {
        if (!is_string($json) || $json === '') {
            return $default;
        }
        $data = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : $default;
    }

    protected function getFieldMapKeys()
    {
        return [
            'name','description_short','description',
            'price_tax_excl','price_tax_incl','tax_rate','promo_price_tax_incl',
            'reference','supplier_reference','ean13','upc','mpn','isbn',
            'manufacturer_name','product_url','cover_image','images','categories','features',
            'weight','width','height','depth',
        ];
    }

    protected function renderLangCheckboxesHtml(array $rows)
    {
        $out = '<div class="well">';
        foreach ($rows as $r) {
            $id = 'lang_'.$r['id'];
            $checked = !empty($r['checked']) ? 'checked' : '';
            // NAJWAŻNIEJSZE: name="languages_select[]" i value=ISO
            $out .= '<label class="checkbox" style="display:block">'
                . '<input type="checkbox" name="languages_select[]" value="'.htmlspecialchars($r['iso'], ENT_QUOTES, 'UTF-8').'" id="'.$id.'" '.$checked.'> '
                . htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8')
                . '</label>';
        }
        return $out.'</div>';
    }


    protected function renderFieldMapTableHtml(array $rows)
    {
        $out  = '<table class="table">';
        $out .= '<thead><tr><th>'.$this->l('Enable').'</th><th>'.$this->l('Key').'</th><th>'.$this->l('Alias (optional)').'</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $checked = $r['enabled'] ? 'checked' : '';
            $alias   = htmlspecialchars($r['alias']);
            $out .= '<tr>'
                .'<td><input type="checkbox" class="js-map-enable" data-key="'.pSQL($r['key']).'" '.$checked.'></td>'
                .'<td><code>'.htmlspecialchars($r['key']).'</code></td>'
                .'<td><input type="text" class="form-control js-map-alias" data-key="'.pSQL($r['key']).'" value="'.$alias.'" placeholder="'.$this->l('use key name if empty').'"></td>'
                .'</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }

    protected function renderComposerJs()
    {
        $js = <<<'JS'
<script>
(function(){
    function collectCategories(){
        var ids = [];
        // HelperTreeCategories generuje checkboxy z name="categoryBox[]"
        document.querySelectorAll('input[name="categoryBox[]"]:checked').forEach(function(el){
            ids.push(parseInt(el.value,10));
        });
        return ids;
    }
    function collectManufacturers(){
        var sel = document.querySelector('[name="filters_manufacturers[]"]');
        var ids = [];
        if (sel) {
            Array.from(sel.selectedOptions).forEach(function(o){ ids.push(parseInt(o.value,10)); });
        }
        return ids;
    }
    function collectLanguages(){
        var iso = [];
        document.querySelectorAll('.js-mg-lang').forEach(function(el){
            if (el.checked) { iso.push(el.getAttribute('data-iso')); }
        });
        return iso;
    }
    function collectCurrencies(){
        var sel = document.querySelector('[name="currencies_select[]"]');
        var iso = [];
        if (sel) {
            Array.from(sel.selectedOptions).forEach(function(o){
                var label = o.textContent || '';
                var m = label.match(/\(([^)]+)\)$/);
                if (m) { iso.push(m[1]); }
            });
        }
        return iso;
    }
    function collectMap(){
        var map = {};
        document.querySelectorAll('.js-map-enable').forEach(function(chk){
            var key = chk.getAttribute('data-key');
            var aliasInput = document.querySelector('.js-map-alias[data-key="'+key+'"]');
            if (chk.checked) {
                var alias = aliasInput && aliasInput.value.trim() ? aliasInput.value.trim() : true;
                map[key] = alias;
            } else {
                map[key] = false;
            }
        });
        return map;
    }
    function onSubmit(e){
        // Złóż JSONy do hiddenów
        var filters = {
            categories: collectCategories(),
            include_children: document.querySelector('[name="filters_include_children"]:checked') ? 1 : 0,
            manufacturers: collectManufacturers(),
            only_active: document.querySelector('[name="filters_only_active"]:checked') ? 1 : 0,
            only_available: document.querySelector('[name="filters_only_available"]:checked') ? 1 : 0,
            min_price: (function(v){ return v===''?null:parseFloat(v); })(document.querySelector('[name="filters_min_price"]').value.trim()),
            max_price: (function(v){ return v===''?null:parseFloat(v); })(document.querySelector('[name="filters_max_price"]').value.trim())
        };
        var langs = collectLanguages();
        var currs = collectCurrencies();
        var fmap  = collectMap();
        var set = function(name, obj){ var el = document.querySelector('[name="'+name+'"]'); if(el){ el.value = JSON.stringify(obj); } };
        set('filters_json', filters);
        set('languages_json', langs);
        set('currencies_json', currs);
        set('field_map_json', fmap);
    }
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', onSubmit);
    }
})();
</script>
JS;
        return $js;
    }

    private function jsonOrNull($s)
    {
        if (!is_string($s)) return null;
        $s = trim($s);
        if ($s === '' || $s === '[]' || $s === '{}') return null; // traktuj jako „puste”
        $d = json_decode($s, true);
        return (json_last_error() === JSON_ERROR_NONE && !empty($d)) ? $d : null;
    }

    protected function composeJsonFromPostedUi()
    {
        @file_put_contents(_PS_ROOT_DIR_.'/var/logs/mgxml_post_debug.txt', print_r($_POST, true), FILE_APPEND);
        $idFeed   = (int)Tools::getValue($this->identifier);
        $existing = ($idFeed > 0) ? new MgXmlFeed($idFeed) : null;
        if ($existing && !Validate::isLoadedObject($existing)) { $existing = null; }

        $enc = function($a){ return json_encode($a, JSON_UNESCAPED_UNICODE); };

        // ===== 1) ZBIÓR Z KONTROLEK UI (to ma najwyższy priorytet) =====
        // Kategorie (HelperTree): categoryBox[]
        $cat = Tools::getValue('categoryBox');
        if ($cat === null) $cat = Tools::getValue('categoryBox[]');

        // Producenci (select multiple): filters_manufacturers[]
        $mans = Tools::getValue('filters_manufacturers');
        if ($mans === null) $mans = Tools::getValue('filters_manufacturers[]');

        $min = Tools::getValue('filters_min_price');
        $max = Tools::getValue('filters_max_price');

        $ctrlFilters = [
            'categories'       => array_map('intval', (array)$cat),
            'include_children' => (int)Tools::getValue('filters_include_children'),
            'manufacturers'    => array_map('intval', (array)$mans),
            'only_active'      => (int)Tools::getValue('filters_only_active'),
            'only_available'   => (int)Tools::getValue('filters_only_available'),
            'min_price'        => ($min === '' ? null : (float)$min),
            'max_price'        => ($max === '' ? null : (float)$max),
        ];
        $ctrlFiltersNonEmpty =
            !empty($ctrlFilters['categories']) ||
            !empty($ctrlFilters['manufacturers']) ||
            !empty($ctrlFilters['only_active']) ||
            !empty($ctrlFilters['only_available']) ||
            $ctrlFilters['min_price'] !== null ||
            $ctrlFilters['max_price'] !== null ||
            !empty($ctrlFilters['include_children']);

        // Waluty (select multiple): currencies_select[]
        $currSel = Tools::getValue('currencies_select');
        if ($currSel === null) $currSel = Tools::getValue('currencies_select[]');
        $currSel = array_map('intval', (array)$currSel);
        $currIso = [];
        foreach ($currSel as $idc) {
            $cur = Currency::getCurrency((int)$idc); // array
            if (!empty($cur['iso_code'])) $currIso[] = (string)$cur['iso_code'];
        }
        $currIso = array_values(array_unique($currIso));

        // Języki (checkboxy): languages_select[]  ← UWAGA: checkboxy MUSZĄ mieć name="languages_select[]"
        $langSel = Tools::getValue('languages_select');
        if ($langSel === null) $langSel = Tools::getValue('languages_select[]');
        $langSel = is_array($langSel) ? array_values(array_unique(array_map('strval', $langSel))) : [];

        // ===== 2) HIDDEN JSONY – traktuj puste []/{} jako „brak” =====
        $filtersJsonArr    = $this->jsonOrNull(Tools::getValue('filters_json'));
        $languagesJsonArr  = $this->jsonOrNull(Tools::getValue('languages_json'));
        $currenciesJsonArr = $this->jsonOrNull(Tools::getValue('currencies_json'));
        $fieldMapJsonArr   = $this->jsonOrNull(Tools::getValue('field_map_json'));

        // ===== 3) PRIORYTETY ZAPISU =====
        // FILTERS: kontrolki UI > niepusty hidden > DB > domyślny obiekt
        if ($ctrlFiltersNonEmpty) {
            $_POST['filters'] = $enc($ctrlFilters);
        } elseif (is_array($filtersJsonArr)) {
            $_POST['filters'] = $enc($filtersJsonArr);
        } elseif ($existing && !empty($existing->filters)) {
            $_POST['filters'] = $existing->filters;
        } else {
            $_POST['filters'] = $enc([
                'categories'=>[], 'include_children'=>0, 'manufacturers'=>[],
                'only_active'=>0, 'only_available'=>0, 'min_price'=>null, 'max_price'=>null
            ]);
        }

        // CURRENCIES: z selecta (ISO) > niepusty hidden > DB > brak
        if (!empty($currIso)) {
            $_POST['currencies'] = $enc($currIso);
        } elseif (is_array($currenciesJsonArr)) {
            $_POST['currencies'] = $enc($currenciesJsonArr);
        } elseif ($existing && !empty($existing->currencies)) {
            $_POST['currencies'] = $existing->currencies;
        }

        // LANGUAGES: z checkboxów > niepusty hidden > DB > brak
        if (!empty($langSel)) {
            $_POST['languages'] = $enc($langSel);
        } elseif (is_array($languagesJsonArr)) {
            $_POST['languages'] = $enc($languagesJsonArr);
        } elseif ($existing && !empty($existing->languages)) {
            $_POST['languages'] = $existing->languages;
        }

        // FIELD MAP: niepusty hidden > DB (UI robi tabelkę, więc tu nie ma kontrolek)
        if (is_array($fieldMapJsonArr)) {
            $_POST['field_map'] = $enc($fieldMapJsonArr);
        } elseif ($existing && !empty($existing->field_map)) {
            $_POST['field_map'] = $existing->field_map;
        }
    }



    /**
     * Wspólny builder – najpierw klasa produkcyjna, potem fallback: CRON (HTTP) i na końcu lokalny include.
     */
    protected function runFeedBuild(MgXmlFeed $feed)
    {
        $started = microtime(true);

        $filters    = $this->jsonDecodeSafe($feed->filters, []);
        $languages  = $this->jsonDecodeSafe($feed->languages, []);
        $currencies = $this->jsonDecodeSafe($feed->currencies, []);
        $fieldMap   = $this->jsonDecodeSafe($feed->field_map, []);

        // 1) Docelowo: serwis builder/exporter (jeśli istnieją)
        if (class_exists('FeedBuilderService') && class_exists('FeedExporter')) {
            $builder  = new FeedBuilderService(Context::getContext());
            $exporter = new FeedExporter($feed);
            $iter = $builder->build($filters, $fieldMap, $languages, $currencies);
            $rows = $exporter->saveFromIterator($iter, [
                'languages'  => $languages,
                'currencies' => $currencies,
                'field_map'  => $fieldMap,
                'gzip'       => (bool)$feed->gzip,
            ]);
            $elapsed = (int)round((microtime(true) - $started) * 1000);

            $feed->last_build_at = date('Y-m-d H:i:s');
            $feed->row_count     = (int)$rows;
            $feed->build_time_ms = (int)$elapsed;
            $feed->update();

            if (class_exists('MgXmlFeedLog')) {
                $log = new MgXmlFeedLog();
                $log->id_feed = (int)$feed->id;
                $log->status  = 'ok';
                $log->rows    = (int)$rows;
                $log->time_ms = (int)$elapsed;
                $log->message = 'BO build (service)';
                $log->add();
            }

            return [ 'status' => 'ok', 'message' => 'Feed generated successfully', 'rows' => (int)$rows, 'time_ms' => (int)$elapsed ];
        }

        // 2) Fallback: CRON przez HTTP (front-controller i/lub plik cron.php) – z różnymi nazwami parametru tokenu
        $base = Tools::getShopDomainSsl(true).__PS_BASE_URI__;

        // zbierz możliwe tokeny
        $tokens = [];
        foreach (['cron_token','token','secure_key'] as $prop) {
            if (property_exists($feed, $prop) && !empty($feed->{$prop})) {
                $tokens[] = (string)$feed->{$prop};
            }
        }
        $mod = Module::getInstanceByName('mgxmlfeeds');
        if ($mod && !empty($mod->secure_key)) {
            $tokens[] = (string)$mod->secure_key;
        }
        $tokens = array_values(array_unique(array_filter($tokens)));

        // zmontuj zestawy zapytań z różnymi nazwami parametru tokenu
        $querySets = [];
        foreach ($tokens as $t) {
            $querySets[] = ['id_feed' => (int)$feed->id, 'cron_token' => $t];
            $querySets[] = ['id_feed' => (int)$feed->id, 'token'      => $t];
            $querySets[] = ['id_feed' => (int)$feed->id, 'secure_key' => $t];
        }
        // spróbuj też bez tokenu (gdy CRON nie wymaga)
        $querySets[] = ['id_feed' => (int)$feed->id];

        $urls = [];
        foreach ($querySets as $qs) {
            // front controller (jeśli istnieje controllers/front/cron.php)
            $urls[] = $this->context->link->getModuleLink('mgxmlfeeds', 'cron', $qs);
            // bezpośrednio do pliku cron.php
            $urls[] = $base.'modules/mgxmlfeeds/cron.php?'.http_build_query($qs, '', '&');
        }

        // pobieranie z cURL lub file_get_contents
        $fetch = function ($url) {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_USERAGENT      => 'mgxmlfeeds-bo-build',
                ]);
                $out = curl_exec($ch);
                curl_close($ch);
                return $out;
            }
            return @file_get_contents($url);
        };

        foreach ($urls as $u) {
            $raw  = $fetch($u);
            $data = $raw ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                continue;
            }
            // akceptuj dwa formaty:
            // 1) {"status":"ok","rows":N}
            // 2) {"status":"done","results":[{"status":"ok","rows":N,...}], ...}
            $ok = false;
            $rows = 0;

            if (($data['status'] ?? '') === 'ok') {
                $ok = true;
                $rows = (int)($data['rows'] ?? 0);
            } elseif (($data['status'] ?? '') === 'done' && !empty($data['results'][0]) && ($data['results'][0]['status'] ?? '') === 'ok') {
                $ok = true;
                $rows = (int)($data['results'][0]['rows'] ?? 0);
            }

            if ($ok) {
                $elapsed = (int)round((microtime(true) - $started) * 1000);
                $feed->last_build_at = date('Y-m-d H:i:s');
                $feed->row_count     = $rows;
                $feed->build_time_ms = $elapsed;
                $feed->update();
                return [
                    'status'  => 'ok',
                    'message' => 'Feed built via cron fallback',
                    'rows'    => $rows,
                    'time_ms' => $elapsed
                ];
            }
        }

        // 3) Ostateczny fallback: lokalny include pliku cron.php (bez HTTP)
        $cronFile = _PS_MODULE_DIR_.'mgxmlfeeds/cron.php';
        if (is_file($cronFile)) {
            foreach ($querySets as $qs) {
                // zachowaj oryginalne $_GET
                $oldGet = $_GET;
                foreach ($qs as $k => $v) { $_GET[$k] = $v; }
                ob_start();
                include $cronFile; // cron powinien zrobić echo JSON i exit/die
                $raw = ob_get_clean();
                // przy niektórych cron.php może nastąpić exit – wtedy nie trafimy tutaj,
                // ale jeśli nie – spróbujmy sparsować odpowiedź
                $data = $raw ? json_decode($raw, true) : null;
                $_GET = $oldGet;
                if (is_array($data)) {
                    $ok = false; $rows = 0;
                    if (($data['status'] ?? '') === 'ok') {
                        $ok = true; $rows = (int)($data['rows'] ?? 0);
                    } elseif (($data['status'] ?? '') === 'done' && !empty($data['results'][0]) && ($data['results'][0]['status'] ?? '') === 'ok') {
                        $ok = true; $rows = (int)($data['results'][0]['rows'] ?? 0);
                    }
                    if ($ok) {
                        $elapsed = (int)round((microtime(true) - $started) * 1000);
                        $feed->last_build_at = date('Y-m-d H:i:s');
                        $feed->row_count     = $rows;
                        $feed->build_time_ms = $elapsed;
                        $feed->update();
                        return [ 'status' => 'ok', 'message' => 'Feed built via local cron include', 'rows' => $rows, 'time_ms' => $elapsed ];
                    }
                }
            }
        }

        // 4) Awaryjny minimalny builder – bez zewnętrznych klas/cron (tylko id, name, url)
        $min = $this->legacyMinimalBuild($feed, $filters, $languages, $currencies, $fieldMap);
        if ($min && !empty($min['rows'])) {
            $elapsed = (int)round((microtime(true) - $started) * 1000);
            $feed->last_build_at = date('Y-m-d H:i:s');
            $feed->row_count     = (int)$min['rows'];
            $feed->build_time_ms = $elapsed;
            $feed->update();
            return [ 'status' => 'ok', 'message' => 'Feed built via minimal fallback', 'rows' => (int)$min['rows'], 'time_ms' => $elapsed ];
        }

        return [ 'status' => 'error', 'message' => 'Builder/Exporter class not found (all fallbacks failed)', 'rows' => 0, 'time_ms' => 0 ];
    }

    /**
     * Minimalny, awaryjny eksport: id_product, name, product_url.
     * Szanuje: only_active, id_shop, języki (jeśli brak → domyślny), basename/ścieżkę cache.
     */
    protected function legacyMinimalBuild(MgXmlFeed $feed, array $filters, array $languages, array $currencies, array $fieldMap)
    {
        $ctx   = $this->context;
        $idShop = (int)$ctx->shop->id;
        $idLangsIso = $languages; // np. ["pl","en"]
        if (!$idLangsIso || !is_array($idLangsIso)) {
            $idLangsIso = [$ctx->language->iso_code];
        }
        // mapuj ISO → id_lang
        $langMap = [];
        foreach (Language::getLanguages(true, $idShop) as $l) {
            $langMap[strtolower($l['iso_code'])] = (int)$l['id_lang'];
        }
        $rowsTotal = 0;

        foreach ($idLangsIso as $iso) {
            $isoLow = strtolower((string)$iso);
            $idLang = isset($langMap[$isoLow]) ? $langMap[$isoLow] : (int)$ctx->language->id;

            // SQL minimalny: id_product + nazwa w danym języku
            $sql = new DbQuery();
            $sql->select('p.id_product, pl.name');
            $sql->from('product', 'p');
            $sql->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop='.(int)$idShop);
            $sql->innerJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang='.(int)$idLang.' AND pl.id_shop='.(int)$idShop);
            if (!empty($filters['only_active'])) {
                $sql->where('ps.active = 1');
            }
            $sql->orderBy('p.id_product ASC');
            $products = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

            // plik wyjściowy
            $baseName = property_exists($feed, 'file_basename') && !empty($feed->file_basename) ? $feed->file_basename : 'feed';
            $dir  = _PS_MODULE_DIR_.'mgxmlfeeds/var/cache/'.(int)$feed->id.'/';
            $this->ensureDir($dir);
            $file = $dir.$baseName.'-'.$idLang.'-'.$idShop.'.xml';

            // zapis XML
            $xw = class_exists('XMLWriter') ? new XMLWriter() : null;
            if ($xw) {
                $xw->openURI($file);
                $xw->startDocument('1.0', 'UTF-8');
                $xw->startElement('items');
                foreach ($products as $pr) {
                    $rowsTotal++;
                    $idp  = (int)$pr['id_product'];
                    $name = (string)$pr['name'];
                    $url  = $ctx->link->getProductLink($idp, null, null, null, $idLang, null, 0, false, false, true);
                    $xw->startElement('item');
                    $xw->writeElement('id', (string)$idp);
                    $xw->writeElement('name', $name);
                    $xw->writeElement('product_url', $url);
                    $xw->endElement();
                }
                $xw->endElement();
                $xw->endDocument();
                $xw->flush();
            } else {
                // prosty zapis gdy brak XMLWriter
                $h = fopen($file, 'wb');
                fwrite($h, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<items>\n");
                foreach ($products as $pr) {
                    $rowsTotal++;
                    $idp  = (int)$pr['id_product'];
                    $name = htmlspecialchars($pr['name'], ENT_QUOTES, 'UTF-8');
                    $url  = htmlspecialchars($ctx->link->getProductLink($idp, null, null, null, $idLang, null, 0, false, false, true), ENT_QUOTES, 'UTF-8');
                    fwrite($h, "  <item>\n    <id>{$idp}</id>\n    <name>{$name}</name>\n    <product_url>{$url}</product_url>\n  </item>\n");
                }
                fwrite($h, "</items>\n");
                fclose($h);
            }
        }

        return ['rows' => $rowsTotal];
    }

    protected function ensureDir($path)
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }
}