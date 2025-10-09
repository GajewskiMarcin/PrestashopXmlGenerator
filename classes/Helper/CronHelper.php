<?php
/**
 * classes/Helper/CronHelper.php
 * Obsługa tokenów CRON, blokad współbieżności i throttlingu.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CronHelper
{
    /** Minimalny odstęp między przebiegami jednego feedu (sekundy) – można nadpisać w URL ?force=1 */
    const MIN_INTERVAL_SEC = 60;

    /** Nazwa pliku locka */
    const LOCK_FILENAME = 'cron.lock';

    /**
     * Weryfikuje token master dla "cron-all".
     */
    public static function verifyMasterToken($token)
    {
        $expected = Configuration::get(Mgxmlfeeds::CONFIG_MASTER_TOKEN);
        return is_string($token) && $token !== '' && hash_equals((string)$expected, (string)$token);
    }

    /**
     * Weryfikuje token feedu.
     */
    public static function verifyFeedToken($idFeed, $token)
    {
        if ((int)$idFeed <= 0 || !is_string($token) || $token === '') {
            return false;
        }
        $expected = Db::getInstance()->getValue('
            SELECT cron_token FROM ' . _DB_PREFIX_ . 'mgxmlfeed WHERE id_feed = ' . (int)$idFeed . ' LIMIT 1
        ');
        return $expected && hash_equals((string)$expected, (string)$token);
    }

    /**
     * Sprawdza, czy można uruchomić generację feedu (throttle).
     * Zwraca true, gdy:
     * - brak ostatniego przebiegu
     * - minęło MIN_INTERVAL_SEC od last_build_at
     * - ?force=1 w URL
     */
    public static function canRun($feedRow, $force = false)
    {
        if ($force) {
            return true;
        }
        if (empty($feedRow['last_build_at'])) {
            return true;
        }
        $last = strtotime($feedRow['last_build_at']);
        if ($last === false) {
            return true;
        }
        return (time() - $last) >= self::MIN_INTERVAL_SEC;
    }

    /**
     * Ścieżka do katalogu roboczego feedu (var/cache/{id_feed})
     */
    public static function getFeedWorkDir($idFeed)
    {
        $path = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$idFeed;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Ścieżka do pliku locka feedu.
     */
    public static function getLockPath($idFeed)
    {
        return rtrim(self::getFeedWorkDir($idFeed), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
    }

    /**
     * Próbuje założyć blokadę (lock file) dla danego feedu.
     * Zwraca uchwyt do pliku (resource) lub false jeśli już zablokowany.
     */
    public static function acquireLock($idFeed)
    {
        $lockPath = self::getLockPath($idFeed);

        // utwórz plik, jeśli nie istnieje
        $fp = @fopen($lockPath, 'c+');
        if (!$fp) {
            return false;
        }

        // ustaw nieblokujący flock
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            // Ktoś już generuje – sprawdź stary lock (>30 min) i ew. przerwij
            $stat = @stat($lockPath);
            if ($stat && isset($stat['mtime']) && (time() - (int)$stat['mtime']) > 1800) {
                // lock zwietrzał – spróbuj przejąć
                if (@flock($fp, LOCK_EX | LOCK_NB)) {
                    ftruncate($fp, 0);
                    fwrite($fp, (string)getmypid());
                    fflush($fp);
                    return $fp;
                }
            }
            fclose($fp);
            return false;
        }

        // zapis PID do pliku
        ftruncate($fp, 0);
        fwrite($fp, (string)getmypid());
        fflush($fp);

        return $fp;
    }

    /**
     * Zdejmuje blokadę.
     */
    public static function releaseLock($lockHandle, $idFeed)
    {
        if (is_resource($lockHandle)) {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
        // Opcjonalnie: pozostaw plik locka (ślad), ale można też czyścić:
        // @unlink(self::getLockPath($idFeed));
    }

    /**
     * Prosty generator tokenu.
     */
    public static function generateToken($length = 40)
    {
        try {
            return bin2hex(random_bytes((int)max(16, ceil($length / 2))));
        } catch (\Exception $e) {
            return Tools::substr(sha1(uniqid('', true)), 0, (int)$length);
        }
    }

    /**
     * Aktualizuje last_build_at i status feedu.
     */
    public static function touchFeed($idFeed, $status = 'running')
    {
        return Db::getInstance()->update('mgxmlfeed', [
            'last_build_at' => date('Y-m-d H:i:s'),
            'last_status'   => pSQL($status),
        ], 'id_feed = ' . (int)$idFeed);
    }

    /**
     * Zwraca informację o ostatnim przebiegu w postaci tablicy.
     */
    public static function getLastBuildInfo($idFeed)
    {
        $sql = new DbQuery();
        $sql->select('last_build_at, last_status, row_count, build_time_ms');
        $sql->from('mgxmlfeed');
        $sql->where('id_feed = ' . (int)$idFeed);
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($sql);
    }
}
