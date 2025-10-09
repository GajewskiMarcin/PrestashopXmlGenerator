<?php
/**
 * classes/MgXmlFeedLog.php
 * Logi generacji feedów XML (ORM dla ps_mgxmlfeed_log)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MgXmlFeedLog extends ObjectModel
{
    /** @var int */
    public $id_log;

    /** @var int */
    public $id_feed;

    /** @var string */
    public $status; // ok | error | running | skipped

    /** @var string|null */
    public $message;

    /** @var int|null */
    public $row_count;

    /** @var int|null */
    public $build_time_ms;

    /** @var string */
    public $started_at;

    /** @var string|null */
    public $finished_at;

    public static $definition = [
        'table' => 'mgxmlfeed_log',
        'primary' => 'id_log',
        'fields' => [
            'id_feed'       => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'status'        => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 32],
            'message'       => ['type' => self::TYPE_HTML, 'validate' => 'isCleanHtml', 'required' => false],
            'row_count'     => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => false],
            'build_time_ms' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt', 'required' => false],
            'started_at'    => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
            'finished_at'   => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => false],
        ],
    ];

    /**
     * Utwórz wpis "running" i zwróć jego ID.
     */
    public static function start($idFeed, $message = null)
    {
        $log = new self();
        $log->id_feed = (int)$idFeed;
        $log->status = 'running';
        $log->message = (string)$message;
        $log->started_at = date('Y-m-d H:i:s');
        $log->finished_at = null;
        $log->add();

        return (int)$log->id;
    }

    /**
     * Zakończ wpis aktualizując status, komunikat, liczby i czasy.
     */
    public static function finish($idLog, $status = 'ok', $message = null, $rowCount = null, $buildTimeMs = null)
    {
        $idLog = (int)$idLog;
        if ($idLog <= 0) {
            return false;
        }

        $data = [
            'status'        => pSQL((string)$status),
            'message'       => pSQL((string)$message, true),
            'row_count'     => $rowCount !== null ? (int)$rowCount : null,
            'build_time_ms' => $buildTimeMs !== null ? (int)$buildTimeMs : null,
            'finished_at'   => date('Y-m-d H:i:s'),
        ];

        return Db::getInstance()->update('mgxmlfeed_log', $data, 'id_log = ' . $idLog);
    }

    /**
     * Jednostrzałowe logowanie bez "running" (np. błędy walidacji).
     */
    public static function logSimple($idFeed, $status, $message, $rowCount = null, $buildTimeMs = null)
    {
        $log = new self();
        $log->id_feed = (int)$idFeed;
        $log->status = (string)$status;
        $log->message = (string)$message;
        $log->row_count = $rowCount !== null ? (int)$rowCount : null;
        $log->build_time_ms = $buildTimeMs !== null ? (int)$buildTimeMs : null;
        $log->started_at = date('Y-m-d H:i:s');
        $log->finished_at = date('Y-m-d H:i:s');
        $log->add();

        return (int)$log->id;
    }

    /**
     * Ostatnie N logów dla feedu.
     */
    public static function getRecentByFeed($idFeed, $limit = 50)
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed_log')
            ->where('id_feed = ' . (int)$idFeed)
            ->orderBy('id_log DESC')
            ->limit((int)$limit);

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    }

    /**
     * Proste czyszczenie starych logów (zostaw ostatnie N wpisów).
     */
    public static function prune($idFeed, $keep = 200)
    {
        $idFeed = (int)$idFeed;
        $keep = (int)$keep;

        // pobierz ID granicznego wpisu (offset keep)
        $sql = (new DbQuery())
            ->select('id_log')
            ->from('mgxmlfeed_log')
            ->where('id_feed = ' . $idFeed)
            ->orderBy('id_log DESC')
            ->limit(1, $keep);

        $border = (int)Db::getInstance()->getValue($sql);
        if ($border > 0) {
            return Db::getInstance()->delete('mgxmlfeed_log', 'id_feed = ' . $idFeed . ' AND id_log < ' . $border);
        }
        return true;
    }
}
