<?php
/**
 * controllers/front/cron.php
 * Generowanie feedów XML (pojedynczo lub wszystkich) po wywołaniu z tokenem CRON.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MgxmlfeedsCronModuleFrontController extends ModuleFrontController
{
    /** @var string */
    protected $modulePath;

    public function init()
    {
        parent::init();
        $this->modulePath = _PS_MODULE_DIR_ . $this->module->name . DIRECTORY_SEPARATOR;
        $this->display_header = false;
        $this->display_footer = false;
        $this->display_column_left = false;
        $this->display_column_right = false;
    }

    public function display()
    {
        $this->runCron();
    }

    protected function runCron()
    {
        $token = Tools::getValue('token');
        $idFeed = (int)Tools::getValue('id');
        $runAll = (bool)Tools::getValue('all', false);

        if (!$token) {
            $this->sendError(400, 'Missing token parameter');
            return;
        }

        if ($runAll) {
            $this->processAllFeeds($token);
            return;
        }

        if ($idFeed <= 0) {
            $this->sendError(400, 'Missing id parameter');
            return;
        }

        $feed = $this->getFeed($idFeed);
        if (!$feed) {
            $this->sendError(404, 'Feed not found');
            return;
        }

        if ($token !== $feed['cron_token']) {
            $this->sendError(403, 'Invalid feed token');
            return;
        }

        $result = $this->generateFeed((int)$feed['id_feed']);
        $this->outputResult([$result]);
    }

    /**
     * Generuje wszystkie aktywne feedy z poprawnym tokenem głównym.
     */
    protected function processAllFeeds($token)
    {
        $masterToken = Configuration::get(Mgxmlfeeds::CONFIG_MASTER_TOKEN);
        if ($token !== $masterToken) {
            $this->sendError(403, 'Invalid master token');
            return;
        }

        $feeds = $this->getAllActiveFeeds();
        if (!$feeds) {
            $this->sendError(404, 'No active feeds found');
            return;
        }

        $results = [];
        foreach ($feeds as $feed) {
            $results[] = $this->generateFeed((int)$feed['id_feed']);
        }

        $this->outputResult($results);
    }

    /**
     * Pobiera jeden feed po ID
     */
    protected function getFeed($idFeed)
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed')
            ->where('id_feed = ' . (int)$idFeed)
            ->limit(1);

        return Db::getInstance()->getRow($sql);
    }

    /**
     * Pobiera wszystkie aktywne feedy
     */
    protected function getAllActiveFeeds()
    {
        $sql = (new DbQuery())
            ->select('*')
            ->from('mgxmlfeed')
            ->where('active = 1');

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Główna logika generacji feedu XML
     * (tu uproszczona wersja — plik powstaje z nagłówkiem XML i przykładową strukturą)
     */
    protected function generateFeed($idFeed)
    {
        $start = microtime(true);
        $feed = $this->getFeed($idFeed);
        if (!$feed) {
            return [
                'id_feed' => $idFeed,
                'status' => 'error',
                'message' => 'Feed not found',
                'time_ms' => 0,
                'rows' => 0,
            ];
        }

        $feedDir = $this->modulePath . 'var/cache/' . (int)$idFeed;
        if (!is_dir($feedDir)) {
            @mkdir($feedDir, 0755, true);
        }

        $fileName = sprintf('%s-%d-%d.xml', $feed['file_basename'], (int)$this->context->language->id, (int)$this->context->shop->id);
        $filePath = $feedDir . DIRECTORY_SEPARATOR . $fileName;

        // Właściwy eksport byłby realizowany przez FeedExporter/FeedBuilderService,
        // tutaj tylko przykład formatu.
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<feed id=\"{$idFeed}\" generated_at=\"" . date('c') . "\">\n";
        $xml .= "  <info>Sample feed placeholder. Full generation logic in FeedBuilderService.</info>\n";
        $xml .= "</feed>\n";

        file_put_contents($filePath, $xml);

        // Ewentualna kompresja
        if ((int)$feed['gzip'] === 1) {
            $gzPath = $filePath . '.gz';
            $gz = gzopen($gzPath, 'w9');
            if ($gz) {
                gzwrite($gz, $xml);
                gzclose($gz);
            }
        }

        $time = (int)((microtime(true) - $start) * 1000);

        // Logowanie
        Db::getInstance()->insert('mgxmlfeed_log', [
            'id_feed' => (int)$idFeed,
            'status' => pSQL('ok'),
            'message' => pSQL('Feed generated successfully'),
            'row_count' => 0,
            'build_time_ms' => $time,
            'started_at' => date('Y-m-d H:i:s', $start),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        // Aktualizacja timestampu feedu
        Db::getInstance()->update('mgxmlfeed', [
            'last_build_at' => date('Y-m-d H:i:s'),
            'last_status' => pSQL('ok'),
            'build_time_ms' => $time,
        ], 'id_feed = ' . (int)$idFeed);

        return [
            'id_feed' => (int)$idFeed,
            'status' => 'ok',
            'message' => 'Feed generated successfully',
            'time_ms' => $time,
            'rows' => 0,
        ];
    }

    /**
     * Wyświetla wyniki wszystkich feedów w JSON
     */
    protected function outputResult(array $results)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'done',
            'count' => count($results),
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Wysyła komunikat błędu w JSON
     */
    protected function sendError($code, $message)
    {
        http_response_code((int)$code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'code' => (int)$code,
            'message' => (string)$message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
