<?php
/**
 * classes/Helper/FileHelper.php
 * Operacje plikowe dla feedów XML: zapis, czyszczenie, TTL, rozmiar, walidacja.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FileHelper
{
    /**
     * Zwraca ścieżkę pliku XML (lub XML.GZ) dla danego feedu, sklepu i języka.
     */
    public static function getFeedFilePath($feed, $idShop, $idLang, $gzip = false)
    {
        $baseDir = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$feed['id_feed'];
        $file = sprintf('%s-%d-%d.xml', $feed['file_basename'], (int)$idLang, (int)$idShop);
        return $gzip ? $baseDir . '/' . $file . '.gz' : $baseDir . '/' . $file;
    }

    /**
     * Tworzy katalog feedu jeśli nie istnieje.
     */
    public static function ensureFeedDir($idFeed)
    {
        $dir = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$idFeed;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Usuwa pliki XML starsze niż podany TTL (minuty).
     */
    public static function cleanupOldFiles($idFeed, $ttlMinutes)
    {
        $dir = self::ensureFeedDir($idFeed);
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.xml*');
        if (!$files) {
            return;
        }

        $ttl = (int)$ttlMinutes * 60;
        $now = time();
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime && ($now - $mtime) > $ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * Zwraca rozmiar pliku w czytelnej formie (np. 12.3 MB)
     */
    public static function getReadableSize($bytes)
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Sprawdza, czy plik istnieje i jest świeży (TTL w minutach).
     */
    public static function isFresh($filePath, $ttlMinutes)
    {
        if (!file_exists($filePath)) {
            return false;
        }
        $ttl = (int)$ttlMinutes * 60;
        $mtime = @filemtime($filePath);
        if (!$mtime) {
            return false;
        }
        return (time() - $mtime) < $ttl;
    }

    /**
     * Zwraca listę plików cache dla danego feedu (XML + GZ)
     */
    public static function listFeedFiles($idFeed)
    {
        $dir = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$idFeed;
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.xml*') as $path) {
            $out[] = [
                'filename' => basename($path),
                'size' => self::getReadableSize(filesize($path)),
                'mtime' => date('Y-m-d H:i:s', filemtime($path)),
            ];
        }
        return $out;
    }

    /**
     * Weryfikuje, czy plik XML jest poprawny (parsowalny).
     * Zwraca true/false, a opcjonalnie komunikat błędu przez referencję.
     */
    public static function validateXml($path, &$errorMsg = null)
    {
        if (!file_exists($path)) {
            $errorMsg = 'File not found';
            return false;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $errorMsg = isset($errors[0]) ? trim($errors[0]->message) : 'Invalid XML';
            libxml_clear_errors();
            return false;
        }
        return true;
    }

    /**
     * Szybka kopia (backup) feedu – zapisuje do var/logs/
     */
    public static function backupFeed($path)
    {
        if (!file_exists($path)) {
            return false;
        }
        $logDir = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $dest = $logDir . '/' . basename($path) . '.' . date('Ymd_His') . '.bak';
        return @copy($path, $dest);
    }

    /**
     * Czyści katalog cache feedu.
     */
    public static function clearFeedCache($idFeed)
    {
        $dir = _PS_MODULE_DIR_ . 'mgxmlfeeds/var/cache/' . (int)$idFeed;
        if (!is_dir($dir)) {
            return true;
        }
        foreach (glob($dir . '/*.xml*') as $f) {
            @unlink($f);
        }
        return true;
    }
}
