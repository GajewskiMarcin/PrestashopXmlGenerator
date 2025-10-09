<?php
/**
 * controllers/admin/AdminMgXmlFeedsController.php
 * Panel BO do zarządzania feedami: lista, formularz, akcje (generuj, token).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/MgXmlFeed.php';

class AdminMgXmlFeedsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        $this->table = 'mgxmlfeed';
        $this->className = 'MgXmlFeed';
        $this->identifier = 'id_feed';
        $this->_defaultOrderBy = 'id_feed';
        $this->_defaultOrderWay = 'DESC';
        $this->explicitSelect = true;

        $this->lang = false;
        $this->list_no_link = false;
        $this->allow_export = false;

        parent::__construct();

        // Kolumny listy
        $this->fields_list = [
            'id_feed' => [
                'title' => $this->l('ID'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ],
            'active' => [
                'title' => $this->l('Active'),
                'align' => 'text-center',
                'type' => 'bool',
                'active' => 'status',
                'orderby' => false,
            ],
            'name' => [
                'title' => $this->l('Name'),
                'filter_key' => 'a!name',
            ],
            'file_basename' => [
                'title' => $this->l('Basename'),
                'align' => 'text-left',
            ],
            'variant_mode' => [
                'title' => $this->l('Mode'),
                'callback' => 'renderVariantMode',
                'orderby' => false,
                'search' => false,
            ],
            'ttl_minutes' => [
                'title' => $this->l('TTL (min)'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ],
            'last_build_at' => [
                'title' => $this->l('Last build'),
                'type' => 'datetime',
            ],
            'last_status' => [
                'title' => $this->l('Status'),
                'align' => 'text-center',
            ],
            'row_count' => [
                'title' => $this->l('Rows'),
                'align' => 'text-center',
                'class' => 'fixed-width-sm',
            ],
            'build_time_ms' => [
                'title' => $this->l('Time [ms]'),
                'align' => 'text-center',
                'class' => 'fixed-width-sm',
            ],
        ];

        // Akcje wiersza
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->addRowAction('build');
        $this->addRowAction('token');

        // Akcje masowe
        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon' => 'icon-trash',
            ],
        ];
    }

    /** Callback listy dla trybu wariantu */
    public function renderVariantMode($value, $row)
    {
        return $value === 'combination' ? $this->l('Per combination') : $this->l('Per product');
    }

    /** Nagłówek z przyciskami */
    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $this->page_header_toolbar_btn['new_feed'] = [
            'href' => self::$currentIndex . '&addmgxmlfeed&token=' . $this->token,
            'desc' => $this->l('Add feed'),
            'icon' => 'process-icon-new',
        ];

        $masterToken = Configuration::get(Mgxmlfeeds::CONFIG_MASTER_TOKEN);
        $cronAllUrl = $this->context->link->getModuleLink(
            'mgxmlfeeds',
            'cron',
            ['all' => 1, 'token' => $masterToken],
            true
        );

        $this->page_header_toolbar_btn['cron_all'] = [
            'href' => $cronAllUrl,
            'desc' => $this->l('Run CRON (all)'),
            'icon' => 'process-icon-refresh',
            'target' => '_blank',
        ];
    }

    /** Przyciski akcji wiersza */
    public function displayBuildLink($token, $id)
    {
        $feed = $this->getFeedRow((int)$id);
        if (!$feed) {
            return '';
        }
        $url = $this->context->link->getModuleLink(
            'mgxmlfeeds',
            'cron',
            ['id' => (int)$id, 'token' => $feed['cron_token']],
            true
        );
        return '<a class="btn btn-default" target="_blank" href="' . htmlspecialchars($url) . '">
                    <i class="icon-refresh"></i> ' . $this->l('Build') . '
                </a>';
    }

    public function displayTokenLink($token, $id)
    {
        $regenUrl = self::$currentIndex . '&regenToken=1&id_feed=' . (int)$id . '&token=' . $this->token;
        return '<a class="btn btn-default" href="' . htmlspecialchars($regenUrl) . '">
                    <i class="icon-key"></i> ' . $this->l('Regenerate token') . '
                </a>';
    }

    /** Pobranie rekordu (do akcji wiersza) — PROSTY SQL, by uniknąć problemów składniowych */
    protected function getFeedRow($idFeed)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'mgxmlfeed WHERE id_feed = ' . (int)$idFeed . ' LIMIT 1';
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }

    /** Formularz edycji/dodawania */
    public function renderForm()
    {
        if (!($obj = $this->loadObject(true))) {
            return '';
        }

        if (empty($obj->cron_token)) {
            $obj->cron_token = $this->generateToken(40);
        }

        $variantOptions = [
            ['id' => 'product', 'name' => $this->l('Per product')],
            ['id' => 'combination', 'name' => $this->l('Per combination')],
        ];

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Feed configuration'),
                'icon' => 'icon-cogs',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Name'),
                    'name'  => 'name',
                    'required' => true,
                    'col' => 6,
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Active'),
                    'name' => 'active',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Enabled')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('File basename'),
                    'name' => 'file_basename',
                    'col'  => 4,
                    'hint' => $this->l('Used in filename: {basename}-{lang}-{shop}.xml'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Variant mode'),
                    'name' => 'variant_mode',
                    'options' => [
                        'query' => $variantOptions,
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('GZIP'),
                    'name' => 'gzip',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'gzip_on', 'value' => 1, 'label' => $this->l('Yes')],
                        ['id' => 'gzip_off', 'value' => 0, 'label' => $this->l('No')],
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('TTL (minutes)'),
                    'name' => 'ttl_minutes',
                    'col'  => 2,
                    'hint' => $this->l('Controls cache headers / freshness.'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Languages (JSON)'),
                    'name' => 'languages',
                    'cols' => 60,
                    'rows' => 2,
                    'desc' => $this->l('Example: ["pl","en"]. Leave empty to use shop default.'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Currencies (JSON)'),
                    'name' => 'currencies',
                    'cols' => 60,
                    'rows' => 2,
                    'desc' => $this->l('Example: ["PLN","EUR"]. Leave empty to use shop default.'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Filters (JSON)'),
                    'name' => 'filters',
                    'cols' => 60,
                    'rows' => 6,
                    'desc' => $this->l('Example: {"categories":[44,51],"manufacturers":[3,5],"only_active":true}'),
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Field map / aliases (JSON)'),
                    'name' => 'field_map',
                    'cols' => 60,
                    'rows' => 6,
                    'desc' => $this->l('Enable/disable fields and set aliases. Example: {"price_tax_incl":"price_brutto","ean13":true}'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('CRON token'),
                    'name' => 'cron_token',
                    'col'  => 6,
                    'hint' => $this->l('Security token for cron trigger (unique per feed).'),
                    'readonly' => true,
                ],
                [
                    'type' => 'free',
                    'label' => $this->l('CRON URL (this feed)'),
                    'name'  => 'cron_url_feed',
                ],
                [
                    'type' => 'free',
                    'label' => $this->l('Feed URL (serve cached file)'),
                    'name'  => 'serve_url_feed',
                ],
                [
                    'type' => 'free',
                    'label' => $this->l('CRON URL (all feeds)'),
                    'name'  => 'cron_url_all',
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
            ],
        ];

        $feedId = (int)$obj->id;
        $cronToken = (string)$obj->cron_token;
        $masterToken = Configuration::get(Mgxmlfeeds::CONFIG_MASTER_TOKEN);

        $cronUrl = $feedId > 0 && $cronToken
            ? $this->context->link->getModuleLink('mgxmlfeeds', 'cron', ['id' => $feedId, 'token' => $cronToken], true)
            : $this->l('Save first to generate token');

        $serveUrl = $feedId > 0
            ? $this->context->link->getModuleLink('mgxmlfeeds', 'feed', ['id' => $feedId], true)
            : $this->l('Save first to get URL');

        $cronAllUrl = $this->context->link->getModuleLink('mgxmlfeeds', 'cron', ['all' => 1, 'token' => $masterToken], true);

        $this->fields_value = [
            'cron_url_feed'  => '<div class="well">' . htmlspecialchars($cronUrl) . '</div>',
            'serve_url_feed' => '<div class="well">' . htmlspecialchars($serveUrl) . '</div>',
            'cron_url_all'   => '<div class="well">' . htmlspecialchars($cronAllUrl) . '</div>',
        ];

        $this->toolbar_btn['regen_token'] = [
            'href' => self::$currentIndex . '&regenToken=1&id_feed=' . (int)$feedId . '&token=' . $this->token,
            'desc' => $this->l('Regenerate token'),
            'icon' => 'process-icon-key',
        ];
        $this->toolbar_btn['build_now'] = [
            'href' => $feedId > 0 ? $cronUrl : '#',
            'desc' => $this->l('Build now'),
            'icon' => 'process-icon-refresh',
            'target' => '_blank',
        ];

        return parent::renderForm();
    }

    /** Obsługa przycisków i zapisów */
    public function postProcess()
    {
        if (Tools::isSubmit('regenToken')) {
            $id = (int)Tools::getValue('id_feed');
            if ($id > 0) {
                $new = $this->generateToken(40);
                Db::getInstance()->update('mgxmlfeed', ['cron_token' => pSQL($new)], 'id_feed = ' . (int)$id);
                $this->confirmations[] = $this->l('Token regenerated.');
            } else {
                $this->errors[] = $this->l('Invalid feed ID.');
            }
        }

        if (Tools::isSubmit('submitAddmgxmlfeed')) {
            $this->validateJsonField('languages');
            $this->validateJsonField('currencies');
            $this->validateJsonField('filters');
            $this->validateJsonField('field_map');

            if (!(int)Tools::getValue('id_mgxmlfeed')) {
                $_POST['cron_token'] = $this->generateToken(40);
            }

            $fb = trim((string)Tools::getValue('file_basename'));
            if ($fb === '') {
                $this->errors[] = $this->l('File basename is required.');
            }
        }

        parent::postProcess();
    }

    protected function validateJsonField($name)
    {
        $val = Tools::getValue($name);
        if ($val === '' || $val === null) {
            return true;
        }
        json_decode($val, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = sprintf($this->l('Invalid JSON in field: %s'), $name);
            return false;
        }
        return true;
    }

    protected function generateToken($length = 32)
    {
        try {
            return bin2hex(random_bytes((int) max(16, ceil($length / 2))));
        } catch (\Exception $e) {
            return Tools::substr(sha1(uniqid('', true)), 0, (int)$length);
        }
    }
}
