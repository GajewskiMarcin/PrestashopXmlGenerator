<?php
/**
 * src/Exporter/FeedExporter.php
 * Główna logika eksportu XML dla pojedynczego feedu (strumieniowo, z paginacją).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Dto/ProductDto.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Helper/ProductQueryHelper.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Helper/XmlWriterHelper.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Helper/FileHelper.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Helper/CronHelper.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/MgXmlFeedLog.php';

class FeedExporter
{
    /** @var Context */
    protected $context;

    /** @var array Rekord feedu z bazy (ps_mgxmlfeed) */
    protected $feed;

    /** @var int */
    protected $idFeed;

    /** @var int */
    protected $idShop;

    /** @var array Konfiguracje feedu po dekodowaniu JSON */
    protected $filters = [];
    protected $fieldMap = [];
    protected $languages = [];   // lista ISO lub puste → użyj domyślnego jęz.
    protected $currencies = [];  // lista ISO lub puste → użyj waluty sklepu

    /** @var bool per-product vs per-combination */
    protected $withCombinations = false;

    /** @var int rozmiar strony (paginacja) */
    protected $pageSize = 1000;

    public function __construct($idFeed)
    {
        $this->context = Context::getContext();
        $this->idFeed = (int)$idFeed;
        $this->feed = $this->getFeedRow($this->idFeed);
        if (!$this->feed) {
            throw new Exception('Feed not found: ' . (int)$idFeed);
        }

        $this->idShop = (int)$this->feed['id_shop'];
        $this->filters = $this->decodeJson($this->feed['filters']) ?: [];
        $this->fieldMap = $this->decodeJson($this->feed['field_map']) ?: [];
        $this->languages = $this->decodeJson($this->feed['languages']) ?: [];
        $this->currencies = $this->decodeJson($this->feed['currencies']) ?: [];
        $this->withCombinations = ((string)$this->feed['variant_mode'] === 'combination');
    }

    /**
     * Uruchamia pełny eksport.
     * Zwraca tablicę z podsumowaniem (row_count, build_time_ms, files[]).
     */
    public function export()
    {
        $startTs = microtime(true);
        $logId = MgXmlFeedLog::start($this->idFeed, 'Feed build started');
        CronHelper::touchFeed($this->idFeed, 'running');

        $lock = CronHelper::acquireLock($this->idFeed);
        if (!$lock) {
            MgXmlFeedLog::finish($logId, 'skipped', 'Another process is generating this feed');
            return [
                'status' => 'skipped',
                'message' => 'Locked by another process',
                'row_count' => 0,
                'build_time_ms' => 0,
                'files' => [],
            ];
        }

        try {
            $files = [];
            $totalRows = 0;

            // Ustal listę języków: jeżeli pusta, użyj języka sklepu.
            $langs = $this->resolveLanguages($this->languages, $this->idShop);
            if (empty($langs)) {
                throw new Exception('No languages available for shop: ' . (int)$this->idShop);
            }

            // Waluta: dla uproszczenia eksport w walucie sklepu; (lista currencies dostępna do rozszerzenia)
            $currencyIso = $this->resolveCurrency($this->currencies, $this->idShop);

            foreach ($langs as $langInfo) {
                $idLang = (int)$langInfo['id_lang'];
                $iso = (string)$langInfo['iso_code'];

                // Ścieżki plików
                $targetDir = FileHelper::ensureFeedDir($this->idFeed);
                $xmlPath = $targetDir . DIRECTORY_SEPARATOR . sprintf('%s-%d-%d.xml', $this->feed['file_basename'], $idLang, $this->idShop);

                // Czyścimy stare pliki zgodnie z TTL
                FileHelper::cleanupOldFiles($this->idFeed, (int)$this->feed['ttl_minutes']);

                // Strumieniowa generacja
                $writer = new XmlWriterHelper();
                $writer->open(
                    $xmlPath,
                    (bool)$this->feed['gzip'],
                    [
                        'shop_id'   => $this->idShop,
                        'lang'      => $iso,
                        'currency'  => $currencyIso,
                        'feed_id'   => (int)$this->idFeed,
                    ],
                    $this->fieldMap
                );

                // Paginacja
                $page = 0;
                $rowsForLang = 0;
                do {
                    $batch = ProductQueryHelper::getProducts(
                        $this->filters,
                        $this->idShop,
                        $idLang,
                        $this->withCombinations,
                        $this->pageSize,
                        $page * $this->pageSize
                    );

                    foreach ($batch as $dto) {
                        $writer->writeProduct($dto);
                    }

                    $rows = count($batch);
                    $rowsForLang += $rows;
                    $totalRows += $rows;
                    $page++;
                } while ($rows === $this->pageSize);

                $writer->close();

                // Ewentualny plik .gz jest tworzony wewnątrz XmlWriterHelper przez parametr gzip
                $files[] = [
                    'lang' => $iso,
                    'xml'  => basename($xmlPath),
                    'gz'   => ((int)$this->feed['gzip'] === 1) ? basename($xmlPath) . '.gz' : null,
                    'rows' => $rowsForLang,
                ];
            }

            $timeMs = (int)((microtime(true) - $startTs) * 1000);

            // Update metryk feedu
            Db::getInstance()->update('mgxmlfeed', [
                'last_build_at' => date('Y-m-d H:i:s'),
                'last_status'   => pSQL('ok'),
                'row_count'     => (int)$totalRows,
                'build_time_ms' => (int)$timeMs,
            ], 'id_feed = ' . (int)$this->idFeed);

            MgXmlFeedLog::finish($logId, 'ok', 'Feed built successfully', $totalRows, $timeMs);

            return [
                'status' => 'ok',
                'message' => 'Feed built successfully',
                'row_count' => $totalRows,
                'build_time_ms' => $timeMs,
                'files' => $files,
            ];
        } catch (Exception $e) {
            $timeMs = (int)((microtime(true) - $startTs) * 1000);
            Db::getInstance()->update('mgxmlfeed', [
                'last_build_at' => date('Y-m-d H:i:s'),
                'last_status'   => pSQL('error'),
                'build_time_ms' => (int)$timeMs,
            ], 'id_feed = ' . (int)$this->idFeed);

            MgXmlFeedLog::finish($logId, 'error', $e->getMessage(), null, $timeMs);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'row_count' => 0,
                'build_time_ms' => $timeMs,
                'files' => [],
            ];
        } finally {
            CronHelper::releaseLock($lock, $this->idFeed);
        }
    }

    /* ===========================
     *        HELPERS
     * =========================== */

    protected function getFeedRow($idFeed)
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed')
            ->where('id_feed = ' . (int)$idFeed)
            ->limit(1);

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }

    protected function decodeJson($str)
    {
        if (!$str) {
            return null;
        }
        $arr = json_decode($str, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $arr : null;
    }

    /**
     * Rozwiązuje listę języków do postaci: [ ['id_lang'=>..,'iso_code'=>..], ... ]
     * Jeżeli wejściowa lista jest pusta → zwraca tylko język sklepu.
     */
    protected function resolveLanguages(array $langList, $idShop)
    {
        $out = [];
        if (!empty($langList)) {
            foreach ($langList as $iso) {
                $idLang = (int)Language::getIdByIso($iso, false);
                if ($idLang) {
                    $out[] = ['id_lang' => $idLang, 'iso_code' => $iso];
                }
            }
        } else {
            $defaultIdLang = (int)Configuration::get('PS_LANG_DEFAULT', null, null, (int)$idShop);
            $lang = new Language($defaultIdLang);
            if ($lang && $lang->id) {
                $out[] = ['id_lang' => (int)$lang->id, 'iso_code' => (string)$lang->iso_code];
            }
        }
        return $out;
    }

    /**
     * Zwraca ISO waluty (z listy lub domyślnej waluty sklepu).
     */
    protected function resolveCurrency(array $currencyList, $idShop)
    {
        if (!empty($currencyList)) {
            // weź pierwszą z listy
            return (string)reset($currencyList);
        }
        $shop = new Shop((int)$idShop);
        $currency = new Currency((int)$shop->id_currency);
        return (string)$currency->iso_code;
    }
}
