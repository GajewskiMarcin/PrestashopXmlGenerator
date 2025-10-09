<?php
/**
 * MG XML Feeds (PS 8/9)
 * Główny plik modułu
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Mgxmlfeeds extends Module
{
    const CONFIG_MASTER_TOKEN = 'MGXMLFEEDS_MASTER_TOKEN';

    const TAB_PARENT_CLASS = 'AdminMG';             // nadrzędna karta "MG"
    const TAB_PARENT_NAME  = 'MG Narzędzia';        // nazwa nadrzędnej karty
    const TAB_CLASS        = 'AdminMgXmlFeeds';     // karta modułu
    const TAB_NAME         = 'MG XML Feeds';        // nazwa karty modułu

    /** @var string absolutna ścieżka do katalogu modułu */
    protected $modulePath;

    public function __construct()
    {
        $this->name = 'mgxmlfeeds';
        $this->tab = 'administration';
        $this->version = '0.7.0';
        $this->author = 'MG';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_]; // kompatybilność PS8/PS9
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('MG XML Feeds');
        $this->description = $this->l('Generowanie wielu plików XML produktów z filtrami, CRON i cachem.');
        $this->confirmUninstall = $this->l('Na pewno odinstalować MG XML Feeds?');

        $this->modulePath = _PS_MODULE_DIR_ . $this->name . DIRECTORY_SEPARATOR;
    }

    /**
     * Instalacja modułu: tabele, konfiguracja, karty BO, katalogi var
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installDb()) {
            return false;
        }

        if (!$this->installConfig()) {
            return false;
        }

        if (!$this->installTabs()) {
            return false;
        }

        if (!$this->ensureVarDirs()) {
            return false;
        }

        return true;
    }

    /**
     * Deinstalacja: usunięcie kart, (opcjonalnie) konfiguracji. Tabel nie ruszamy domyślnie.
     */
    public function uninstall()
    {
        $ok = true;

        $ok = $ok && $this->uninstallTabs();
        $ok = $ok && $this->uninstallConfig();
        $ok = $ok && parent::uninstall();

        return (bool)$ok;
    }

    /**
     * Przekierowanie do kontrolera BO modułu
     */
    public function getContent()
    {
        // Bezpośrednio do karty AdminMgXmlFeeds
        $link = $this->context->link->getAdminLink(self::TAB_CLASS);
        Tools::redirectAdmin($link);
        return ''; // nigdy nie osiągane
    }

    /* ======================
     *   DB
     * ====================== */

    protected function installDb()
    {
        $engine = _MYSQL_ENGINE_;
        $prefix = pSQL(_DB_PREFIX_);

        // ps_mgxmlfeed: definicja feedu
        $sql1 = "
            CREATE TABLE IF NOT EXISTS `{$prefix}mgxmlfeed` (
              `id_feed` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `active` TINYINT(1) NOT NULL DEFAULT 1,
              `name` VARCHAR(255) NOT NULL,
              `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
              `id_shop_group` INT UNSIGNED NOT NULL DEFAULT 1,
              `languages` JSON NULL,
              `currencies` JSON NULL,
              `filters` JSON NULL,
              `field_map` JSON NULL,
              `variant_mode` ENUM('product','combination') NOT NULL DEFAULT 'product',
              `cron_token` VARCHAR(64) NOT NULL,
              `file_basename` VARCHAR(255) NOT NULL,
              `gzip` TINYINT(1) NOT NULL DEFAULT 0,
              `ttl_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
              `last_build_at` DATETIME NULL,
              `last_status` VARCHAR(32) NULL,
              `row_count` INT UNSIGNED NULL,
              `build_time_ms` INT UNSIGNED NULL,
              `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `date_upd` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_feed`),
              KEY `id_shop` (`id_shop`),
              KEY `active` (`active`)
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;
        ";

        // ps_mgxmlfeed_log: logi generacji
        $sql2 = "
            CREATE TABLE IF NOT EXISTS `{$prefix}mgxmlfeed_log` (
              `id_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `id_feed` INT UNSIGNED NOT NULL,
              `status` VARCHAR(32) NOT NULL,
              `message` TEXT NULL,
              `row_count` INT UNSIGNED NULL,
              `build_time_ms` INT UNSIGNED NULL,
              `started_at` DATETIME NOT NULL,
              `finished_at` DATETIME NULL,
              PRIMARY KEY (`id_log`),
              KEY `id_feed` (`id_feed`),
              CONSTRAINT `fk_mgxmlfeed_log_feed`
                FOREIGN KEY (`id_feed`) REFERENCES `{$prefix}mgxmlfeed` (`id_feed`)
                ON DELETE CASCADE
            ) ENGINE={$engine} DEFAULT CHARSET=utf8mb4;
        ";

        return Db::getInstance()->execute($sql1) && Db::getInstance()->execute($sql2);
    }

    /* ======================
     *   CONFIG
     * ====================== */

    protected function installConfig()
    {
        // Master token do CRON-all; 32+ znaków
        $token = $this->generateToken(40);
        return Configuration::updateValue(self::CONFIG_MASTER_TOKEN, $token);
    }

    protected function uninstallConfig()
    {
        // Zostawiamy token? Zazwyczaj można usunąć.
        return Configuration::deleteByName(self::CONFIG_MASTER_TOKEN);
    }

    protected function generateToken($length = 32)
    {
        try {
            return bin2hex(random_bytes((int) max(16, ceil($length / 2))));
        } catch (\Exception $e) {
            return Tools::substr(sha1(uniqid('', true)), 0, (int)$length);
        }
    }

    /* ======================
     *   TABS (karty BO)
     * ====================== */

    protected function installTabs()
    {
        // Utwórz lub znajdź rodzica AdminMG
        $parentId = (int)Tab::getIdFromClassName(self::TAB_PARENT_CLASS);
        if (!$parentId) {
            $parent = new Tab();
            $parent->active = 1;
            $parent->class_name = self::TAB_PARENT_CLASS;
            $parent->id_parent = 0; // top-level
            $parent->module = $this->name;
            foreach (Language::getLanguages(false) as $lang) {
                $parent->name[(int)$lang['id_lang']] = self::TAB_PARENT_NAME;
            }
            if (!$parent->add()) {
                return false;
            }
            $parentId = (int)$parent->id;
        }

        // Karta modułu
        if (!(int)Tab::getIdFromClassName(self::TAB_CLASS)) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = self::TAB_CLASS;
            $tab->id_parent = $parentId;
            $tab->module = $this->name;
            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[(int)$lang['id_lang']] = self::TAB_NAME;
            }
            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    protected function uninstallTabs()
    {
        $ok = true;

        // Usuń dziecko
        $idChild = (int)Tab::getIdFromClassName(self::TAB_CLASS);
        if ($idChild) {
            $tab = new Tab($idChild);
            $ok = $ok && (bool)$tab->delete();
        }

        // Jeśli rodzic nie ma dzieci – usuń
        $idParent = (int)Tab::getIdFromClassName(self::TAB_PARENT_CLASS);
        if ($idParent) {
            $children = Tab::getTabs($this->context->language->id, $idParent);
            if (empty($children)) {
                $pt = new Tab($idParent);
                $ok = $ok && (bool)$pt->delete();
            }
        }

        return $ok;
    }

    /* ======================
     *   VAR katalogi
     * ====================== */

    protected function ensureVarDirs()
    {
        $paths = [
            $this->modulePath . 'var',
            $this->modulePath . 'var/cache',
            $this->modulePath . 'var/logs',
        ];

        foreach ($paths as $dir) {
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    return false;
                }
            }
            // Zabezpieczenie index.php
            $index = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }
        return true;
    }
}
