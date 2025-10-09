<?php
/**
 * classes/MgXmlFeed.php
 * Klasa modelu pojedynczego feedu XML (ORM dla ps_mgxmlfeed)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MgXmlFeed extends ObjectModel
{
    /** @var int */
    public $id_feed;

    /** @var int */
    public $active = 1;

    /** @var string */
    public $name;

    /** @var int */
    public $id_shop = 1;

    /** @var int */
    public $id_shop_group = 1;

    /** @var string|null JSON */
    public $languages;

    /** @var string|null JSON */
    public $currencies;

    /** @var string|null JSON */
    public $filters;

    /** @var string|null JSON */
    public $field_map;

    /** @var string ENUM('product','combination') */
    public $variant_mode = 'product';

    /** @var string */
    public $cron_token;

    /** @var string */
    public $file_basename;

    /** @var int */
    public $gzip = 0;

    /** @var int */
    public $ttl_minutes = 60;

    /** @var string|null */
    public $last_build_at;

    /** @var string|null */
    public $last_status;

    /** @var int|null */
    public $row_count;

    /** @var int|null */
    public $build_time_ms;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'mgxmlfeed',
        'primary' => 'id_feed',
        'fields' => [
            'active'         => ['type' => self::TYPE_BOOL, 'validate' => 'isBool', 'required' => true],
            'name'           => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 255],
            'id_shop'        => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_shop_group'  => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'languages'      => ['type' => self::TYPE_STRING, 'validate' => 'isJson', 'required' => false],
            'currencies'     => ['type' => self::TYPE_STRING, 'validate' => 'isJson', 'required' => false],
            'filters'        => ['type' => self::TYPE_STRING, 'validate' => 'isJson', 'required' => false],
            'field_map'      => ['type' => self::TYPE_STRING, 'validate' => 'isJson', 'required' => false],
            'variant_mode'   => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32],
            'cron_token'     => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64],
            'file_basename'  => ['type' => self::TYPE_STRING, 'validate' => 'isFileName', 'required' => true, 'size' => 255],
            'gzip'           => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'ttl_minutes'    => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'last_build_at'  => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat'],
            'last_status'    => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32],
            'row_count'      => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'build_time_ms'  => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'date_add'       => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat'],
            'date_upd'       => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat'],
        ],
    ];

    /**
     * Zwraca ścieżkę katalogu cache dla tego feedu
     */
    public function getCacheDir()
    {
        $path = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$this->id;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Generuje nazwę pliku XML dla danego sklepu/języka
     */
    public function getFilename($idShop, $idLang, $gzip = false)
    {
        $basename = $this->file_basename ?: ('feed-' . (int)$this->id);
        $name = sprintf('%s-%d-%d.xml', $basename, (int)$idLang, (int)$idShop);
        return $gzip ? $name . '.gz' : $name;
    }

    /**
     * Walidacja prostych JSON-ów (pomocniczo)
     */
    public static function isJson($str)
    {
        if ($str === '' || $str === null) {
            return true;
        }
        json_decode($str, true);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Szybkie pobranie tokenu dla feedu
     */
    public static function getTokenById($idFeed)
    {
        return Db::getInstance()->getValue('SELECT cron_token FROM ' . _DB_PREFIX_ . 'mgxmlfeed WHERE id_feed = ' . (int)$idFeed);
    }

    /**
     * Tworzy nowy token i zapisuje
     */
    public function regenerateToken()
    {
        $token = Tools::substr(sha1(uniqid(mt_rand(), true)), 0, 40);
        Db::getInstance()->update('mgxmlfeed', ['cron_token' => pSQL($token)], 'id_feed = ' . (int)$this->id);
        $this->cron_token = $token;
        return $token;
    }

    /**
     * Zwraca pełne dane feedu jako tablicę (dla debug/export)
     */
    public function toArray()
    {
        return [
            'id_feed' => (int)$this->id,
            'active' => (int)$this->active,
            'name' => $this->name,
            'id_shop' => (int)$this->id_shop,
            'variant_mode' => $this->variant_mode,
            'file_basename' => $this->file_basename,
            'gzip' => (int)$this->gzip,
            'ttl_minutes' => (int)$this->ttl_minutes,
            'last_build_at' => $this->last_build_at,
            'last_status' => $this->last_status,
            'row_count' => (int)$this->row_count,
            'build_time_ms' => (int)$this->build_time_ms,
        ];
    }
}
