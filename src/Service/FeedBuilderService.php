<?php
/**
 * src/Service/FeedBuilderService.php
 * Serwis do budowania feedów XML (orchestrator nad FeedExporter).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/src/Exporter/FeedExporter.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/Helper/CronHelper.php';
require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/MgXmlFeedLog.php';

class FeedBuilderService
{
    /**
     * Główna metoda generująca jeden feed.
     *
     * @param int  $idFeed
     * @param bool $force  Pomija throttle (MIN_INTERVAL_SEC) jeśli true
     * @return array{status:string,message:string,row_count:int,build_time_ms:int,files:array}
     */
    public static function build($idFeed, $force = false)
    {
        $idFeed = (int)$idFeed;

        // Pobierz definicję feedu
        $feed = self::getFeedRow($idFeed);
        if (!$feed) {
            return [
                'status' => 'error',
                'message' => 'Feed not found',
                'row_count' => 0,
                'build_time_ms' => 0,
                'files' => [],
            ];
        }

        if ((int)$feed['active'] !== 1) {
            return [
                'status' => 'skipped',
                'message' => 'Feed is disabled',
                'row_count' => 0,
                'build_time_ms' => 0,
                'files' => [],
            ];
        }

        // Throttle (chyba że force=1)
        if (!CronHelper::canRun($feed, (bool)$force)) {
            return [
                'status' => 'skipped',
                'message' => 'Throttled by MIN_INTERVAL_SEC',
                'row_count' => 0,
                'build_time_ms' => 0,
                'files' => [],
            ];
        }

        // Orkiestracja eksportu
        try {
            $exporter = new FeedExporter($idFeed);
            $result = $exporter->export(); // zajmuje się lockiem, logami i update metryk
            return $result;
        } catch (Exception $e) {
            // awaryjny wpis logów (gdyby export() nie zdążył)
            MgXmlFeedLog::logSimple($idFeed, 'error', $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'row_count' => 0,
                'build_time_ms' => 0,
                'files' => [],
            ];
        }
    }

    /**
     * Buduje wszystkie aktywne feedy (z tokenem master w kontrolerze).
     *
     * @param bool $force
     * @return array[] lista rezultatów
     */
    public static function buildAll($force = false)
    {
        $feeds = self::getAllActiveFeeds();
        $out = [];
        foreach ($feeds as $f) {
            $out[] = self::build((int)$f['id_feed'], $force);
        }
        return $out;
    }

    /* ===========================
     *          HELPERS
     * =========================== */

    protected static function getFeedRow($idFeed)
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed')
            ->where('id_feed = ' . (int)$idFeed)
            ->limit(1);

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }

    protected static function getAllActiveFeeds()
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed')
            ->where('active = 1');

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql) ?: [];
    }
}
