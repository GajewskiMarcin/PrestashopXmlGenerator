<?php
/**
 * controllers/front/feed.php
 * Serwowanie gotowego pliku XML z cache (ETag/Last-Modified) + tryb podglądu (Smarty).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MgxmlfeedsFeedModuleFrontController extends ModuleFrontController
{
    /** @var string absolutna ścieżka do katalogu modułu */
    protected $modulePath;

    public function init()
    {
        parent::init();
        $this->modulePath = _PS_MODULE_DIR_ . $this->module->name . DIRECTORY_SEPARATOR;
        // layout wyłączamy tylko w trybie download (XML). W podglądzie chcemy normalny layout.
        $this->display_header = true;
        $this->display_footer = true;
        $this->display_column_left = false;
        $this->display_column_right = false;
    }

    public function display()
    {
        // Parametry
        $idFeed   = (int)Tools::getValue('id');
        $idShop   = (int)Tools::getValue('id_shop', (int)$this->context->shop->id);
        $langIso  = (string)Tools::getValue('lang'); // np. "pl"
        $download = (bool)Tools::getValue('download', false);

        if ($idFeed <= 0) {
            $this->sendErrorXmlOrHtml(400, 'Missing parameter: id', $download);
            return;
        }

        // Ustal id_lang z opcjonalnego ISO
        $idLang = (int)$this->context->language->id;
        if ($langIso) {
            $lang = Language::getIdByIso($langIso, false);
            if (!$lang) {
                $this->sendErrorXmlOrHtml(400, 'Invalid lang ISO', $download);
                return;
            }
            $idLang = (int)$lang;
        }

        // Pobierz definicję feedu
        $feed = $this->getFeedRow($idFeed);
        if (!$feed) {
            $this->sendErrorXmlOrHtml(404, 'Feed not found', $download);
            return;
        }
        if ((int)$feed['active'] !== 1) {
            $this->sendErrorXmlOrHtml(403, 'Feed disabled', $download);
            return;
        }

        // Ścieżki plików cache
        $dir    = $this->modulePath . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $idFeed;
        $xml    = $this->buildFilename($feed, $idShop, $idLang, false);
        $gz     = $this->buildFilename($feed, $idShop, $idLang, true);
        $xmlPath = $dir . DIRECTORY_SEPARATOR . $xml;
        $gzPath  = $dir . DIRECTORY_SEPARATOR . $gz;

        // Tryb pobrania czystego XML
        if ($download) {
            $this->display_header = false;
            $this->display_footer = false;
            $this->serveXml($feed, $xmlPath, $gzPath, $idFeed, $idShop, $idLang);
            return;
        }

        // Tryb podglądu (HTML): przekaż dane do Smarty
        $fileExists = file_exists($xmlPath) || file_exists($gzPath);
        $cronUrl = $this->context->link->getModuleLink(
            'mgxmlfeeds',
            'cron',
            ['id' => (int)$idFeed, 'token' => $feed['cron_token']],
            true
        );
        $downloadUrl = $this->context->link->getModuleLink(
            'mgxmlfeeds',
            'feed',
            ['id' => (int)$idFeed, 'lang' => $langIso ?: Language::getIsoById($idLang), 'id_shop' => (int)$idShop, 'download' => 1],
            true
        );

        // Do szablonu przekazujemy najważniejsze pola + kontekst
        $feedForTpl = $feed;
        $feedForTpl['id_lang'] = (int)$idLang;
        $feedForTpl['id_shop'] = (int)$idShop;
        $feedForTpl['file_basename'] = (string)$feed['file_basename'];
        $feedForTpl['cron_token'] = (string)$feed['cron_token'];

        // Nagłówki anty-cache dla podglądu
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $this->context->smarty->assign([
            'feed'        => $feedForTpl,
            'file_exists' => $fileExists,
            'xml_url'     => $downloadUrl,
            'cron_url'    => $cronUrl,
            'module'      => $this->module,
            'link'        => $this->context->link,
        ]);

        // Render podglądu
        $this->setTemplate('module:mgxmlfeeds/views/templates/hook/feed.tpl');
    }

    /* ======================
     *  LOGIKA SERWOWANIA XML
     * ====================== */

    protected function serveXml(array $feed, $xmlPath, $gzPath, $idFeed, $idShop, $idLang)
    {
        // Czy klient akceptuje gzip?
        $acceptsGzip = $this->clientAcceptsGzip();
        $useGzipFile = file_exists($gzPath) && $acceptsGzip;

        // Preferuj gzip jeśli dostępny i akceptowany, w przeciwnym razie czysty XML
        $path = $useGzipFile ? $gzPath : $xmlPath;

        if (!file_exists($path)) {
            // Próba alternatywy (np. brak gzipu)
            $altPath = $useGzipFile ? $xmlPath : $gzPath;
            if (file_exists($altPath) && (!$useGzipFile || $acceptsGzip)) {
                $path = $altPath;
                $useGzipFile = (substr($altPath, -3) === '.gz');
            } else {
                $this->sendError(404, 'Feed not generated yet. Run cron to build.');
                return;
            }
        }

        $lastModified = filemtime($path);
        $etag = $this->buildEtag($path, $idFeed, $idShop, $idLang);

        // Obsługa If-None-Match / If-Modified-Since
        if ($this->isNotModified($etag, $lastModified)) {
            header('HTTP/1.1 304 Not Modified');
            exit;
        }

        // Nagłówki odpowiedzi
        header('Content-Type: application/xml; charset=utf-8');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        header('ETag: "' . $etag . '"');
        header('Cache-Control: public, max-age=3600, must-revalidate');

        if ($useGzipFile) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
        }

        header('X-MGXML-Feed: ' . (int)$idFeed);
        header('X-MGXML-Lang: ' . (int)$idLang);
        header('X-MGXML-Shop: ' . (int)$idShop);

        $this->streamFile($path);
        exit;
    }

    /**
     * Zwraca rekord feedu z bazy
     */
    protected function getFeedRow($idFeed)
    {
        $id = (int)$idFeed;
        if ($id <= 0) {
            return null;
        }
        require_once _PS_MODULE_DIR_ . 'mgxmlfeeds/classes/MgXmlFeed.php';
        $obj = new MgXmlFeed($id);
        if (!Validate::isLoadedObject($obj)) {
            return null;
        }
        return [
            'id_feed'       => (int)$obj->id,
            'active'        => (int)$obj->active,
            'file_basename' => (string)$obj->file_basename,
            'cron_token'    => (string)$obj->cron_token,
        ];
    }

    /**
     * Buduje nazwę pliku dla cache: {basename}-{lang}-{shop}.xml(.gz)
     */
    protected function buildFilename(array $feed, $idShop, $idLang, $gzip = false)
    {
        $basename = !empty($feed['file_basename']) ? $feed['file_basename'] : ('feed-' . (int)$feed['id_feed']);
        $lang = (int)$idLang;
        $shop = (int)$idShop;
        $name = sprintf('%s-%d-%d.xml', $basename, $lang, $shop);
        return $gzip ? $name . '.gz' : $name;
    }

    /**
     * Sprawdza czy klient akceptuje gzip
     */
    protected function clientAcceptsGzip()
    {
        $accept = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? (string)$_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        return (stripos($accept, 'gzip') !== false);
    }

    /**
     * Generuje ETag na podstawie pliku i parametrów
     */
    protected function buildEtag($filePath, $idFeed, $idShop, $idLang)
    {
        $stat = @stat($filePath);
        $parts = [
            'f' . (int)$idFeed,
            's' . (int)$idShop,
            'l' . (int)$idLang,
            'sz' . ($stat ? (int)$stat['size'] : 0),
            'mt' . ($stat ? (int)$stat['mtime'] : 0),
        ];
        return sha1(implode('-', $parts));
    }

    /**
     * 304 Not Modified?
     */
    protected function isNotModified($etag, $lastModified)
    {
        $ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') : null;
        $ifModifiedSince = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : null;

        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return true;
        }
        if ($ifModifiedSince && $ifModifiedSince >= (int)$lastModified) {
            return true;
        }
        return false;
    }

    /**
     * Strumieniowe wysyłanie pliku
     */
    protected function streamFile($path)
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            $this->sendError(500, 'Cannot open feed file');
            return;
        }
        $size = @filesize($path);
        if ($size !== false) {
            header('Content-Length: ' . (string)$size);
        }
        while (!feof($fp)) {
            echo fread($fp, 8192);
        }
        fclose($fp);
    }

    /**
     * Błąd XML (download) lub HTML (podgląd)
     */
    protected function sendErrorXmlOrHtml($statusCode, $message, $download)
    {
        if ($download) {
            $this->sendError($statusCode, $message);
            return;
        }
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        http_response_code((int)$statusCode);
        $this->context->smarty->assign([
            'feed'        => null,
            'file_exists' => false,
            'xml_url'     => null,
            'cron_url'    => null,
            'error_code'  => (int)$statusCode,
            'error_msg'   => (string)$message,
            'module'      => $this->module,
            'link'        => $this->context->link,
        ]);
        $this->setTemplate('module:mgxmlfeeds/views/templates/hook/feed.tpl');
    }

    /**
     * Wysyłka błędu w XML (dla trybu download)
     */
    protected function sendError($statusCode, $message)
    {
        http_response_code((int)$statusCode);
        header('Content-Type: application/xml; charset=utf-8');
        $xml = sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error><code>%d</code><message>%s</message></error>",
            (int)$statusCode,
            htmlspecialchars((string)$message, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        );
        echo $xml;
        exit;
    }
}