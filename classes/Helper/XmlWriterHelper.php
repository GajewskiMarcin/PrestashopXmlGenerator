<?php
/**
 * classes/Helper/XmlWriterHelper.php
 * Strumieniowa generacja XML dla feedów (XMLWriter, pamięciooszczędnie)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class XmlWriterHelper
{
    /** @var XMLWriter */
    protected $xml;

    /** @var resource|false uchwyt do pliku wynikowego (gdy nie gzip) */
    protected $fp = false;

    /** @var resource|false uchwyt do pliku .gz (gdy gzip) */
    protected $gz = false;

    /** @var string pełna ścieżka do pliku (xml lub xml.gz) */
    protected $targetPath;

    /** @var bool czy używać gzip */
    protected $useGzip = false;

    /** @var array mapowanie nazw pól (aliasy); true = użyj domyślnej nazwy, string = alias */
    protected $fieldMap = [];

    /** @var array atrybuty root-a (np. shop_id, lang, currency) */
    protected $rootAttributes = [];

    /**
     * Inicjalizacja pisarza XML.
     *
     * @param string $targetPath docelowa ścieżka pliku (bez .gz – rozszerzenie dobierane automatycznie)
     * @param bool   $gzip       czy generować .gz
     * @param array  $rootAttributes atrybuty dla elementu <products>
     * @param array  $fieldMap   mapa: klucz => alias|true|false (false = wyłącz)
     */
    public function open($targetPath, $gzip = false, array $rootAttributes = [], array $fieldMap = [])
    {
        $this->useGzip = (bool)$gzip;
        $this->fieldMap = (array)$fieldMap;
        $this->rootAttributes = (array)$rootAttributes;

        // Ustal właściwą ścieżkę
        $this->targetPath = $this->useGzip ? ($targetPath . '.gz') : $targetPath;

        // Upewnij się, że katalog istnieje
        $dir = dirname($this->targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Otwórz zasób wyjściowy
        if ($this->useGzip) {
            $this->gz = gzopen($this->targetPath, 'w9');
            if (!$this->gz) {
                throw new Exception('Cannot open gzip target: ' . $this->targetPath);
            }
            // XMLWriter zapisze do bufora w pamięci, a my co jakiś czas zrzucimy do gz
            $this->xml = new XMLWriter();
            $this->xml->openMemory();
        } else {
            $this->fp = fopen($this->targetPath, 'wb');
            if (!$this->fp) {
                throw new Exception('Cannot open target: ' . $this->targetPath);
            }
            $this->xml = new XMLWriter();
            $this->xml->openUri($this->targetPath); // bez bufora pośredniego
        }

        $this->xml->startDocument('1.0', 'UTF-8');
        $this->xml->setIndent(true);

        // <products ...>
        $this->xml->startElement('products');
        $this->writeAttributes(array_merge([
            'generated_at' => date('c'),
        ], $this->rootAttributes));
        $this->flushBuffer();
    }

    /**
     * Zapis pojedynczego produktu (DTO) do XML.
     *
     * @param ProductDto $p
     */
    public function writeProduct(ProductDto $p)
    {
        $this->xml->startElement('product');
        $this->writeAttributes([
            'id' => (int)$p->id_product,
            'id_combination' => (int)$p->id_product_attribute,
        ]);

        // identifiers
        $this->openIfEnabled('identifiers', true);
        $this->writeNode('reference', $p->reference);
        $this->writeNode('supplier_reference', $p->supplier_reference);
        $this->writeNode('ean13', $p->ean13);
        $this->writeNode('isbn', $p->isbn);
        $this->writeNode('upc', $p->upc);
        $this->writeNode('mpn', $p->mpn);
        $this->writeNode('slug', $p->slug);
        $this->closeIfOpened('identifiers');

        // names / descriptions
        $this->openIfEnabled('names', true);
        $this->writeNode('name', $p->name, true);
        $this->closeIfOpened('names');

        $this->openIfEnabled('descriptions', true);
        $this->writeNode('short_description', $p->description_short, true);
        $this->writeNode('description', $p->description, true);
        $this->writeNode('meta_title', $p->meta_title, true);
        $this->writeNode('meta_description', $p->meta_description, true);
        $this->closeIfOpened('descriptions');

        // categories
        $this->openIfEnabled('categories', true);
        $this->writeNode('main', $p->main_category);
        $this->writeNode('path', $p->category_path, true);
        if (!empty($p->categories)) {
            $this->xml->startElement($this->map('all'));
            foreach ($p->categories as $cat) {
                // cat może być stringiem lub tablicą z id/nazwą
                if (is_array($cat)) {
                    $this->xml->startElement($this->map('category'));
                    if (isset($cat['id'])) {
                        $this->xml->writeAttribute('id', (int)$cat['id']);
                    }
                    $this->xml->text($this->safeText($cat['name'] ?? ''));
                    $this->xml->endElement();
                } else {
                    $this->writeNode('category', (string)$cat);
                }
            }
            $this->xml->endElement();
        }
        $this->closeIfOpened('categories');

        // manufacturer / supplier
        $this->openIfEnabled('manufacturer', true);
        $this->writeNode('name', $p->manufacturer_name);
        $this->writeNode('url', $p->manufacturer_url);
        $this->closeIfOpened('manufacturer');

        if (!empty($p->supplier_name)) {
            $this->openIfEnabled('supplier', true);
            $this->writeNode('name', $p->supplier_name);
            $this->closeIfOpened('supplier');
        }

        // pricing
        $this->openIfEnabled('pricing', true);
        $this->writeNode('price_tax_excl', $this->fmtNum($p->price_tax_excl));
        $this->writeNode('price_tax_incl', $this->fmtNum($p->price_tax_incl));
        $this->writeNode('tax_rate', $this->fmtNum($p->tax_rate));
        if ($p->promo_price_tax_incl !== null) {
            $this->xml->startElement($this->map('promo'));
            $this->writeAttributes([
                'active' => 1,
                'from'   => $p->promo_from,
                'to'     => $p->promo_to,
            ]);
            $this->writeNode('promo_price_tax_incl', $this->fmtNum($p->promo_price_tax_incl));
            $this->xml->endElement();
        }
        $this->writeNode('currency', $p->currency);
        $this->closeIfOpened('pricing');

        // stock
        $this->openIfEnabled('stock', true);
        $this->writeNode('quantity', (int)$p->quantity);
        $this->writeNode('available_for_order', $p->available_for_order ? 'true' : 'false');
        $this->writeNode('minimal_quantity', (int)$p->minimal_quantity);
        $this->writeNode('availability_label', $p->availability_label);
        $this->closeIfOpened('stock');

        // shipping / dimensions
        $this->openIfEnabled('shipping', true);
        $this->writeNode('weight', $this->fmtNum($p->weight), false, ['unit' => 'kg']);
        $this->writeNode('width',  $this->fmtNum($p->width),  false, ['unit' => 'cm']);
        $this->writeNode('height', $this->fmtNum($p->height), false, ['unit' => 'cm']);
        $this->writeNode('depth',  $this->fmtNum($p->depth),  false, ['unit' => 'cm']);
        $this->writeNode('additional_shipping_cost', $this->fmtNum($p->additional_shipping_cost));
        $this->closeIfOpened('shipping');

        // media
        $this->openIfEnabled('media', true);
        $this->writeNode('cover_image', $p->cover_image);
        if (!empty($p->images)) {
            $this->xml->startElement($this->map('all_images'));
            foreach ($p->images as $img) {
                $this->writeNode('image', (string)$img);
            }
            $this->xml->endElement();
        }
        $this->closeIfOpened('media');

        // links
        $this->openIfEnabled('links', true);
        $this->writeNode('product_url', $p->product_url);
        $this->writeNode('category_url', $p->category_url);
        $this->closeIfOpened('links');

        // features
        if (!empty($p->features)) {
            $this->xml->startElement($this->map('features'));
            foreach ($p->features as $f) {
                $this->xml->startElement($this->map('feature'));
                if (is_array($f)) {
                    if (isset($f['id']))  $this->xml->writeAttribute('id', (int)$f['id']);
                    if (isset($f['key'])) $this->xml->writeAttribute('key', (string)$f['key']);
                    $this->xml->writeElement($this->map('name'), $this->safeText((string)($f['name'] ?? '')));
                    $this->xml->writeElement($this->map('value'), $this->safeText((string)($f['value'] ?? '')));
                } elseif ($f instanceof FeatureDto) {
                    $this->xml->writeAttribute('id', (int)$f->id_feature);
                    if ($f->key) {
                        $this->xml->writeAttribute('key', (string)$f->key);
                    }
                    $this->xml->writeElement($this->map('name'), $this->safeText((string)$f->name));
                    $this->xml->writeElement($this->map('value'), $this->safeText((string)$f->value));
                } else {
                    $this->xml->text($this->safeText((string)$f));
                }
                $this->xml->endElement();
            }
            $this->xml->endElement();
        }

        // attributes (gdy kombinacja)
        if (!empty($p->attributes)) {
            $this->xml->startElement($this->map('combination'));
            foreach ($p->attributes as $a) {
                $this->xml->startElement($this->map('attribute'));
                if (is_array($a)) {
                    if (isset($a['group'])) $this->xml->writeElement($this->map('group'), $this->safeText((string)$a['group']));
                    if (isset($a['name']))  $this->xml->writeElement($this->map('name'),  $this->safeText((string)$a['name']));
                }
                $this->xml->endElement();
            }
            $this->xml->endElement();
        }

        // meta
        $this->openIfEnabled('meta', true);
        $this->writeNode('condition', $p->condition);
        $this->writeNode('visibility', $p->visibility);
        $this->writeNode('online_only', $p->online_only ? 'true' : 'false');
        $this->writeNode('unit_price', $this->fmtNum($p->unit_price));
        $this->writeNode('unit', $p->unit);
        $this->writeNode('is_virtual', $p->is_virtual ? 'true' : 'false');
        $this->writeNode('date_add', $p->date_add);
        $this->writeNode('date_upd', $p->date_upd);
        $this->writeNode('last_price_change', $p->last_price_change);
        if (!empty($p->tags)) {
            $this->xml->startElement($this->map('tags'));
            foreach ($p->tags as $t) {
                $this->writeNode('tag', (string)$t);
            }
            $this->xml->endElement();
        }
        if (!empty($p->attachments)) {
            $this->xml->startElement($this->map('attachments'));
            foreach ($p->attachments as $att) {
                $this->xml->startElement($this->map('attachment'));
                if (is_array($att)) {
                    if (isset($att['id'])) $this->xml->writeAttribute('id', (int)$att['id']);
                    $this->writeNode('name', (string)($att['name'] ?? ''), true);
                    $this->writeNode('file', (string)($att['file'] ?? ''), false);
                } else {
                    $this->xml->text($this->safeText((string)$att));
                }
                $this->xml->endElement();
            }
            $this->xml->endElement();
        }
        $this->closeIfOpened('meta');

        $this->xml->endElement(); // </product>
        $this->flushBuffer();
    }

    /**
     * Zamyka dokument i zapisuje bufor (dla gzip).
     */
    public function close()
    {
        if (!$this->xml) {
            return;
        }
        $this->xml->endElement(); // </products>
        $this->xml->endDocument();

        $this->flushBuffer(true);

        if ($this->gz) {
            gzclose($this->gz);
            $this->gz = false;
        }
        if ($this->fp) {
            // przy openUri nie trzymamy uchwytu, ale zostawiamy dla spójności
            fclose($this->fp);
            $this->fp = false;
        }
    }

    /* ===========================
     *    POMOCNICZE METODY
     * =========================== */

    /**
     * Mapowanie nazwy pola wg $fieldMap:
     * - jeśli $fieldMap['price_tax_incl'] === 'price_brutto' → zwróci 'price_brutto'
     * - jeśli $fieldMap['ean13'] === false → pole wyłączone (zwróci null)
     * - w innym wypadku zwróci oryginalną nazwę
     */
    protected function map($key)
    {
        if ($this->fieldMap === null) {
            return $key;
        }
        if (array_key_exists($key, $this->fieldMap)) {
            $m = $this->fieldMap[$key];
            if ($m === false) {
                return null; // wyłączone
            }
            if ($m === true) {
                return $key;
            }
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
        return $key;
    }

    /**
     * Rozpoczyna element jeśli nie wyłączony aliasem. Używaj pary z closeIfOpened().
     */
    protected function openIfEnabled($key, $returnBool = false)
    {
        $mapped = $this->map($key);
        if ($mapped === null) {
            // wyłączony
            if ($returnBool) {
                $this->_stack[] = null;
                return false;
            }
            return;
        }
        $this->xml->startElement($mapped);
        if ($returnBool) {
            $this->_stack[] = $mapped;
            return true;
        }
    }

    /**
     * Zamyka element jeśli był otwarty przez openIfEnabled().
     */
    protected function closeIfOpened($key)
    {
        if (!isset($this->_stack) || !is_array($this->_stack) || empty($this->_stack)) {
            return;
        }
        $mapped = array_pop($this->_stack);
        if ($mapped !== null) {
            $this->xml->endElement();
        }
    }

    /**
     * Zapisuje pojedynczy element z opcjonalnym CDATA i atrybutami.
     * Jeśli alias wyłączony (map() === null) – nic nie zapisuje.
     *
     * @param string $key
     * @param mixed  $value
     * @param bool   $cdata
     * @param array  $attributes
     */
    protected function writeNode($key, $value, $cdata = false, array $attributes = [])
    {
        $tag = $this->map($key);
        if ($tag === null) {
            return; // wyłączone
        }
        if ($value === null || $value === '') {
            // dla części odbiorców brak węzła jest lepszy niż pusty string
            return;
        }

        $this->xml->startElement($tag);
        $this->writeAttributes($attributes);

        if ($cdata) {
            $this->xml->writeCdata($this->safeCdata((string)$value));
        } else {
            $this->xml->text($this->safeText((string)$value));
        }

        $this->xml->endElement();
    }

    /**
     * Zapisuje atrybuty elementu (pomija null/empty).
     */
    protected function writeAttributes(array $attrs)
    {
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $this->xml->writeAttribute($k, (string)$v);
        }
    }

    /**
     * Oczyszczanie tekstu do XML (nie-CDATA).
     */
    protected function safeText($str)
    {
        // XMLWriter->text sam robi encje, ale obetnij kontrolne i normalizuj \r\n
        $str = str_replace(["\r\n", "\r"], "\n", (string)$str);
        // usuń niedozwolone znaki kontrolne
        return preg_replace('/[^\P{C}\t\n]+/u', '', $str);
    }

    /**
     * Zawartość do CDATA – unikniecie sekwencji `]]>`.
     */
    protected function safeCdata($str)
    {
        $str = (string)$str;
        // Rozbij sekwencję zamykającą CDATA
        return str_replace(']]>', ']]]]><![CDATA[>', $str);
    }

    /**
     * Zrzuca bufor do pliku .gz (jeśli używamy openMemory).
     */
    protected function flushBuffer($final = false)
    {
        if (!$this->useGzip) {
            return; // przy openUri nie buforujemy w pamięci
        }
        if (!$this->gz || !$this->xml) {
            return;
        }
        $buf = $this->xml->outputMemory($final);
        if ($buf !== '') {
            gzwrite($this->gz, $buf);
        }
    }
}
