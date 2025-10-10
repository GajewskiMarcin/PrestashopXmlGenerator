<?php
/**
 * controllers/admin/AdminMgXmlFeedsController.php
 * Back Office controller for mgxmlfeeds: list, form, row actions (build, regenerate token).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/MgXmlFeed.php';

class AdminMgXmlFeedsController extends ModuleAdminController
{
    /** @var string Primary key column name for mgxmlfeed table */
    protected $pk;

    public function __construct()
    {
        $this->bootstrap      = true;
        $this->table          = 'mgxmlfeed';
        $this->className      = 'MgXmlFeed';
        $this->lang           = false;
        $this->allow_export   = false;
        $this->list_no_link   = false;
        $this->explicitSelect = false;

        // Determine PK from ObjectModel definition (safer across schema variants)
        $this->pk = (isset(MgXmlFeed::$definition['primary']) && MgXmlFeed::$definition['primary'])
            ? (string) MgXmlFeed::$definition['primary']
            : 'id_feed';

        $this->identifier       = $this->pk;
        $this->_defaultOrderBy  = $this->pk;
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        // List columns
        $this->fields_list = [
            $this->pk => [
                'title' => $this->l('ID'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ],
            'active' => [
                'title'  => $this->l('Active'),
                'align'  => 'text-center',
                'type'   => 'bool',
                'active' => 'status',
                'class'  => 'fixed-width-sm',
            ],
            'name' => [
                'title' => $this->l('Name'),
            ],
            'file_basename' => [
                'title' => $this->l('Basename'),
            ],
            'variant_mode' => [
                'title'    => $this->l('Mode'),
                'callback' => 'renderVariantMode',
                'orderby'  => false,
                'search'   => false,
            ],
            'ttl_minutes' => [
                'title' => $this->l('TTL (min)'),
                'align' => 'text-center',
                'class' => 'fixed-width-xs',
            ],
            'last_build_at' => [
                'title' => $this->l('Last build'),
                'type'  => 'datetime',
                'align' => 'text-center',
            ],
        ];

        // Row actions
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->addRowAction('build');
        $this->addRowAction('token');

        // Bulk delete
        $this->bulk_actions = [
            'delete' => [
                'text'    => $this->l('Delete selected'),
                'confirm' => $this->l('Delete selected items?'),
                'icon'    => 'icon-trash',
            ],
        ];
    }

    public function renderVariantMode($echo, $row)
    {
        $mode = isset($row['variant_mode']) ? (string)$row['variant_mode'] : 'product';
        return $mode === 'combination' ? $this->l('Per combination') : $this->l('Per product');
    }

    public function renderList()
    {
        // Add "new" button
        $this->toolbar_btn['new'] = [
            'href' => self::$currentIndex.'&add'.$this->table.'&token='.$this->token,
            'desc' => $this->l('Add new feed'),
        ];

        // Header button - run CRON for all feeds (uses master token)
        $this->initToolbar();
        $this->addHeaderToolbarBtn();

        return parent::renderList();
    }

    protected function addHeaderToolbarBtn()
    {
        $master = Configuration::get('MGXMLFEEDS_MASTER_TOKEN');
        $cronAllUrl = $this->context->link->getModuleLink('mgxmlfeeds', 'cron', ['all' => 1, 'token' => $master], true);

        $this->page_header_toolbar_btn['cron_all'] = [
            'href'   => $cronAllUrl,
            'desc'   => $this->l('Run CRON (all)'),
            'icon'   => 'process-icon-refresh',
            'target' => '_blank',
        ];
    }

    /** Row action: build feed now */
    public function displayBuildLink($token, $id)
    {
$id = (int)$id;
        if ($id <= 0) {
            return '';
        }

        $feed = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($feed) || empty($feed->cron_token)) {
            return '';
        }

        $url = $this->context->link->getModuleLink(
            'mgxmlfeeds',
            'cron',
            ['id' => (int)$feed->id, 'token' => (string)$feed->cron_token],
            true
        );

        return '<a class="btn btn-default" target="_blank" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">
                    <i class="icon-refresh"></i> ' . $this->l('Build') . '
                </a>';
}

    /** Row action: regenerate token */
    public function displayTokenLink($token, $id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return '';
        }

        $regenUrl = self::$currentIndex . '&regenToken=1&' . $this->identifier . '=' . $id . '&token=' . $this->token;

        return '<a class="btn btn-default" href="' . htmlspecialchars($regenUrl, ENT_QUOTES, 'UTF-8') . '">
                    <i class="icon-key"></i> ' . $this->l('Regenerate token') . '
                </a>';
    }

    /** Safe single row fetch by PK (prevents '... WHERE =  LIMIT 1') */
    protected function getFeedRow($idValue)
    {
        $id = (int)$idValue;
        if ($id <= 0) {
            return null;
        }
        $pk = $this->identifier ?: $this->pk ?: 'id_feed';
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'mgxmlfeed` WHERE `' . bqSQL($pk) . '` = ' . $id . ' LIMIT 1';
        $row = Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
        return $row ?: null;
    }

    /** Add/Edit form */
    public function renderForm()
    {
        if (!($obj = $this->loadObject(true))) {
            return '';
        }

        if (empty($obj->cron_token)) {
            $obj->cron_token = $this->generateToken(40);
        }

        $variantOptions = [
            ['id' => 'product',     'name' => $this->l('Per product')],
            ['id' => 'combination', 'name' => $this->l('Per combination')],
        ];

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Feed configuration'),
            ],
            'input' => [
                [
                    'type'   => 'switch',
                    'label'  => $this->l('Active'),
                    'name'   => 'active',
                    'is_bool'=> true,
                    'values' => [
                        ['id' => 'active_on',  'value' => 1, 'label' => $this->l('Enabled')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Disabled')],
                    ],
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('File basename'),
                    'name'  => 'file_basename',
                    'col'   => 4,
                    'hint'  => $this->l('Used in filename: {basename}-{lang}-{shop}.xml'),
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('Name (internal)'),
                    'name'  => 'name',
                    'col'   => 6,
                ],
                [
                    'type'    => 'select',
                    'label'   => $this->l('Variant mode'),
                    'name'    => 'variant_mode',
                    'options' => [
                        'query' => $variantOptions,
                        'id'    => 'id',
                        'name'  => 'name',
                    ],
                    'hint' => $this->l('Per product or per combination row mode'),
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('Languages (JSON)'),
                    'name'  => 'languages',
                    'desc'  => $this->l('Example: ["pl","en"]'),
                    'col'   => 8,
                    'class' => 'fixed-font',
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('Currencies (JSON)'),
                    'name'  => 'currencies',
                    'desc'  => $this->l('Example: ["PLN","EUR"]'),
                    'col'   => 8,
                    'class' => 'fixed-font',
                ],
                [
                    'type'  => 'textarea',
                    'label' => $this->l('Filters (JSON)'),
                    'name'  => 'filters',
                    'rows'  => 4,
                    'col'   => 8,
                    'desc'  => $this->l('Example: {"categories":[2,3], "manufacturers":[1], "only_active":true, "only_available":true, "min_price":0, "max_price":null}'),
                    'class' => 'fixed-font',
                ],
                [
                    'type'  => 'textarea',
                    'label' => $this->l('Field map (JSON)'),
                    'name'  => 'field_map',
                    'rows'  => 5,
                    'col'   => 8,
                    'desc'  => $this->l('Example: {"name":true, "description":"opis", "ean13":true, "reference":"sku"}'),
                    'class' => 'fixed-font',
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('TTL (minutes)'),
                    'name'  => 'ttl_minutes',
                    'col'   => 2,
                    'hint'  => $this->l('Cache time-to-live for generated file'),
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('CRON token'),
                    'name'  => 'cron_token',
                    'col'   => 6,
                    'hint'  => $this->l('Used to protect CRON/build endpoint'),
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
            ],
        ];

        $this->fields_value = [
            'active'        => (int)$obj->active,
            'name'          => (string)$obj->name,
            'file_basename' => (string)$obj->file_basename,
            'variant_mode'  => (string)$obj->variant_mode ?: 'product',
            'languages'     => (string)$obj->languages,
            'currencies'    => (string)$obj->currencies,
            'filters'       => (string)$obj->filters,
            'field_map'     => (string)$obj->field_map,
            'ttl_minutes'   => (int)$obj->ttl_minutes,
            'cron_token'    => (string)$obj->cron_token,
        ];

        return parent::renderForm();
    }

    public function postProcess()
    {
        // Regenerate token
        if (Tools::isSubmit('regenToken')) {
            $id = (int)Tools::getValue($this->identifier);
            if ($id > 0) {
                $feed = new MgXmlFeed($id);
                if (Validate::isLoadedObject($feed)) {
                    $feed->cron_token = $this->generateToken(40);
                    $feed->save();
                    $this->confirmations[] = $this->l('Token regenerated');
                } else {
                    $this->errors[] = $this->l('Feed not found');
                }
            }
        }

        // Defensive JSON validation (until we add click-based UI)
if (Tools::isSubmit('submitAdd'.$this->table)) {
            foreach (['languages', 'currencies', 'filters', 'field_map'] as $name) {
            $val = Tools::getValue($name);
            if ($val === '' || $val === null) {
                continue;
            }
            if (!$this->isJson($val)) {
                $this->errors[] = sprintf($this->l('Invalid JSON in field: %s'), $name);
            }
        }
        }

        parent::postProcess();
    }

    protected function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string, true);
        return json_last_error() === JSON_ERROR_NONE;
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
