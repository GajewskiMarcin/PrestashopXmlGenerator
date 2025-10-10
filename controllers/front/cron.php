<?php
/**
 * controllers/front/cron.php
 * Generowanie realnego XML (na podstawie DB PrestaShop) z uwzględnieniem filtrów feeda.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MgxmlfeedsCronModuleFrontController extends ModuleFrontController
{
    protected $modulePath;

    public function init()
    {
        parent::init();
        $this->modulePath = _PS_MODULE_DIR_ . 'mgxmlfeeds' . DIRECTORY_SEPARATOR;
    }

    public function display()
    {
        $this->runCron();
    }

    protected function runCron()
    {
        $token  = (string)Tools::getValue('token');
        $idFeed = (int)Tools::getValue('id');
        $runAll = (bool)Tools::getValue('all', false);

        if (!$token) {
            return $this->sendError(400, 'Missing token parameter');
        }

        $master = Configuration::get('MGXMLFEEDS_MASTER_TOKEN');

        if ($runAll) {
            if (!$master || !hash_equals((string)$master, $token)) {
                return $this->sendError(403, 'Invalid master token');
            }
            $feeds = $this->getAllActiveFeeds();
            if (!$feeds) {
                return $this->sendError(404, 'No active feeds found');
            }
            $results = [];
            foreach ($feeds as $row) {
                $results[] = $this->generateFeed((int)$row['id_feed']);
            }
            return $this->outputJson([
                'status'  => 'done',
                'count'   => count($results),
                'results' => $results,
            ]);
        }

        if ($idFeed <= 0) {
            return $this->sendError(400, 'Missing id parameter');
        }

        $feedObj = $this->loadFeed($idFeed);
        if (!$feedObj) {
            return $this->sendError(404, 'Feed not found');
        }

        $tokenOk = (!empty($feedObj->cron_token) && hash_equals((string)$feedObj->cron_token, $token))
            || ($master && hash_equals((string)$master, $token));
        if (!$tokenOk) {
            return $this->sendError(403, 'Invalid token');
        }

        $result = $this->generateFeed($idFeed);
        return $this->outputJson([
            'status'  => 'done',
            'count'   => 1,
            'results' => [$result],
        ]);
    }

    /** ========== GŁÓWNY EKSPORT ========== */
    protected function generateFeed($idFeed)
    {
        $startTs = microtime(true);
        $feed = $this->loadFeed($idFeed);
        if (!$feed || !(int)$feed->active) {
            return [
                'id_feed' => (int)$idFeed,
                'status'  => 'error',
                'message' => 'Feed not found or disabled',
                'time_ms' => 0,
                'rows'    => 0,
            ];
        }

        // Ustal języki i sklep
        $idShop  = (int)$this->context->shop->id;
        $langIds = $this->resolveLanguageIdsFromFeed($feed);
        if (!$langIds) {
            $langIds = [(int)$this->context->language->id];
        }

        $totalRows   = 0;
        $lastFilePath = null;

        foreach ($langIds as $idLang) {
            $filePath = $this->getTargetPath($feed, $idLang, $idShop);
            $this->ensureDir(dirname($filePath));

            // Pobierz produkty wg filtrów z feeda
            $filters  = $this->decodeJson($feed->filters, []);
            $products = $this->fetchProducts($idShop, $idLang, $filters);

            // Zapisz XML
            $rows = $this->writeXml($filePath, $idFeed, $idLang, $idShop, $products);
            $totalRows += $rows;
            $lastFilePath = $filePath;

            // GZIP?
            if ((int)$feed->gzip === 1) {
                $this->gzipFile($filePath);
            }
        }

        $timeMs = (int)round((microtime(true) - $startTs) * 1000);

        // Log (opcjonalnie)
        if (file_exists(_PS_MODULE_DIR_.'mgxmlfeeds/classes/MgXmlFeedLog.php')) {
            require_once _PS_MODULE_DIR_.'mgxmlfeeds/classes/MgXmlFeedLog.php';
            try {
                $log = new MgXmlFeedLog();
                $log->id_feed  = (int)$idFeed;
                $log->status   = 'ok';
                $log->rows     = (int)$totalRows;
                $log->time_ms  = (int)$timeMs;
                $log->save();
            } catch (\Exception $e) {}
        }

        // Aktualizacja statystyk
        try {
            $feed->last_build_at = date('Y-m-d H:i:s');
            $feed->last_status   = 'ok';
            $feed->row_count     = (int)$totalRows;
            $feed->build_time_ms = (int)$timeMs;
            $feed->save();
        } catch (\Exception $e) {}

        return [
            'id_feed' => (int)$idFeed,
            'status'  => 'ok',
            'message' => 'Feed generated successfully',
            'time_ms' => $timeMs,
            'rows'    => (int)$totalRows,
            'file'    => (string)$lastFilePath,
        ];
    }

    /**
     * Pobiera produkty wg filtrów feeda (kategorie / aktywność / dostępność / cena)
     * Uwaga: id producenta w PS jest w tabeli product (p.id_manufacturer), NIE w product_shop.
     */
    protected function fetchProducts($idShop, $idLang, array $filters)
    {
        $onlyActive    = !empty($filters['only_active']);
        $onlyAvailable = !empty($filters['only_available']);
        $minPrice      = (isset($filters['min_price']) && $filters['min_price'] !== null) ? (float)$filters['min_price'] : null;
        $maxPrice      = (isset($filters['max_price']) && $filters['max_price'] !== null) ? (float)$filters['max_price'] : null;
        $categories    = isset($filters['categories']) && is_array($filters['categories']) ? array_filter(array_map('intval', $filters['categories'])) : [];
        $manufacturers = isset($filters['manufacturers']) && is_array($filters['manufacturers']) ? array_filter(array_map('intval', $filters['manufacturers'])) : [];

        $q = new DbQuery();
        $q->select('DISTINCT p.id_product, pl.name, pl.link_rewrite, ps.price, p.id_manufacturer, p.reference, p.ean13');
        $q->from('product', 'p');
        $q->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = '.(int)$idShop);
        $q->innerJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_lang = '.(int)$idLang.' AND pl.id_shop = '.(int)$idShop);

        if ($categories) {
            $q->innerJoin('category_product', 'cp', 'cp.id_product = p.id_product AND cp.id_category IN ('.implode(',', array_map('intval', $categories)).')');
        }
        if ($onlyActive) {
            $q->where('ps.active = 1');
        }
        if ($onlyAvailable) {
            $q->leftJoin('stock_available', 'sa', 'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = '.(int)$idShop);
            $q->where('COALESCE(sa.quantity, 0) > 0');
        }
        if ($manufacturers) {
            $q->where('p.id_manufacturer IN ('.implode(',', array_map('intval', $manufacturers)).')');
        }
        // Filtr po cenie netto z product_shop.price
        if ($minPrice !== null) {
            $q->where('ps.price >= '.(float)$minPrice);
        }
        if ($maxPrice !== null) {
            $q->where('ps.price <= '.(float)$maxPrice);
        }
        // Widoczność – po kolumnie z tabeli product (działa w 1.6/1.7)
        $q->where("p.visibility IN ('both','catalog','search')");

        $rows = Db::getInstance()->executeS($q);
        if (!$rows) {
            return [];
        }

        // Uzupełnij dane pochodne (URL, obrazek, ceny brutto/netto)
        $out = [];
        foreach ($rows as $r) {
            $idp         = (int)$r['id_product'];
            $name        = (string)$r['name'];
            $linkRewrite = (string)$r['link_rewrite'];
            $idMan       = (int)$r['id_manufacturer'];

            $url = $this->context->link->getProductLink($idp, $linkRewrite, null, null, (int)$idLang, (int)$idShop);

            $coverUrl = '';
            $cover = Image::getCover($idp);
            if ($cover && isset($cover['id_image'])) {
                $coverUrl = $this->context->link->getImageLink($linkRewrite, (int)$cover['id_image'], ImageType::getFormattedName('large_default'));
                if (strpos($coverUrl, 'http') !== 0) {
                    $coverUrl = $this->context->link->getBaseLink((int)$idShop, null) . $coverUrl;
                }
            }

            // Ceny – wersja kompatybilna z PHP 8 (bez argumentu by-ref #13)
            $price_ttc = Product::getPriceStatic($idp, true, null, 6);
            $price_ht  = Product::getPriceStatic($idp, false, null, 6);

            $out[] = [
                'id_product'        => $idp,
                'name'              => $name,
                'reference'         => (string)$r['reference'],
                'ean13'             => (string)$r['ean13'],
                'manufacturer_name' => $idMan ? Manufacturer::getNameById($idMan) : '',
                'product_url'       => $url,
                'cover_image'       => $coverUrl,
                'price_tax_incl'    => (float)$price_ttc,
                'price_tax_excl'    => (float)$price_ht,
            ];
        }
        return $out;
    }

    /** Zapisuje XML dla danego języka/sklepu */
    protected function writeXml($filePath, $idFeed, $idLang, $idShop, array $products)
    {
        $w = new XMLWriter();
        $w->openURI($filePath);
        $w->startDocument('1.0', 'UTF-8');
        $w->setIndent(true);

        $w->startElement('feed');
        $w->writeAttribute('id', (string)$idFeed);
        $w->writeAttribute('generated_at', date('c'));
        $w->writeAttribute('id_lang', (string)$idLang);
        $w->writeAttribute('id_shop', (string)$idShop);

        $w->startElement('products');
        foreach ($products as $p) {
            $w->startElement('product');
            $w->writeElement('id_product',        (string)$p['id_product']);
            $w->writeElement('name',              $p['name']);
            if (!empty($p['reference']))         { $w->writeElement('reference', $p['reference']); }
            if (!empty($p['ean13']))             { $w->writeElement('ean13', $p['ean13']); }
            if (!empty($p['manufacturer_name'])) { $w->writeElement('manufacturer_name', $p['manufacturer_name']); }
            if (!empty($p['product_url']))       { $w->writeElement('product_url', $p['product_url']); }
            if (!empty($p['cover_image']))       { $w->writeElement('cover_image', $p['cover_image']); }
            $w->writeElement('price_tax_excl',    number_format((float)$p['price_tax_excl'], 2, '.', ''));
            $w->writeElement('price_tax_incl',    number_format((float)$p['price_tax_incl'], 2, '.', ''));
            $w->endElement(); // product
        }
        $w->endElement(); // products

        $w->endElement(); // feed
        $w->endDocument();
        $w->flush();

        return count($products);
    }

    /** ========== POMOCNICZE ========== */

    protected function loadFeed($idFeed)
    {
        require_once _PS_MODULE_DIR_.'mgxmlfeeds/classes/MgXmlFeed.php';
        $obj = new MgXmlFeed((int)$idFeed);
        return Validate::isLoadedObject($obj) ? $obj : null;
    }

    protected function getAllActiveFeeds()
    {
        $q = (new DbQuery())
            ->select('id_feed')
            ->from('mgxmlfeed')
            ->where('active = 1');
        return Db::getInstance()->executeS($q) ?: [];
    }

    protected function resolveLanguageIdsFromFeed($feed)
    {
        $isoList = $this->decodeJson($feed->languages, []);
        if (!$isoList || !is_array($isoList)) {
            return [];
        }
        $ids = [];
        foreach ($isoList as $iso) {
            $id = (int)Language::getIdByIso((string)$iso, false);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    protected function getTargetPath($feed, $idLang, $idShop)
    {
        $dir  = $this->modulePath . 'var' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . (int)$feed->id;
        $base = (string)$feed->file_basename ?: 'feed';
        return $dir . DIRECTORY_SEPARATOR . sprintf('%s-%d-%d.xml', $base, (int)$idLang, (int)$idShop);
    }

    protected function ensureDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    protected function gzipFile($filePath)
    {
        $gz = @gzopen($filePath . '.gz', 'w9');
        if ($gz) {
            $fh = @fopen($filePath, 'rb');
            if ($fh) {
                while (!feof($fh)) {
                    gzwrite($gz, fread($fh, 8192));
                }
                fclose($fh);
            }
            gzclose($gz);
        }
    }

    protected function decodeJson($str, $fallback)
    {
        if (!is_string($str) || $str === '') {
            return $fallback;
        }
        $d = json_decode($str, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($d)) ? $d : $fallback;
    }

    protected function outputJson($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function sendError($code, $message)
    {
        http_response_code((int)$code);
        $this->outputJson([
            'status'  => 'error',
            'code'    => (int)$code,
            'message' => (string)$message,
        ]);
    }
}
