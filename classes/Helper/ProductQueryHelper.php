<?php
/**
 * classes/Helper/ProductQueryHelper.php
 * Pomocnik SQL do pobierania danych produktów dla eksportu XML
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductQueryHelper
{
    /**
     * Pobiera produkty według filtrów feedu.
     *
     * @param array $filters   — np. ["categories"=>[12,15], "manufacturers"=>[3], "only_active"=>true]
     * @param int   $idShop
     * @param int   $idLang
     * @param bool  $withCombinations
     * @param int   $limit
     * @param int   $offset
     * @return ProductDto[]
     */
    public static function getProducts(array $filters, $idShop, $idLang, $withCombinations = false, $limit = 1000, $offset = 0)
    {
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);

        $sql = new DbQuery();
        $sql->select('p.id_product, pl.name, pl.link_rewrite AS slug, pl.description_short, pl.description, pl.meta_title, pl.meta_description');
        $sql->select('p.reference, p.supplier_reference, p.ean13, p.mpn, p.isbn, p.upc');
        $sql->select('m.name AS manufacturer_name, s.name AS supplier_name');
        $sql->select('ps.price AS price_tax_excl, ps.id_tax_rules_group');
        $sql->select('ps.active, ps.id_shop');
        $sql->select('ps.available_for_order, ps.minimal_quantity, ps.condition, ps.visibility, ps.online_only');
        $sql->select('sa.quantity, sa.physical_quantity, sa.reserved_quantity');
        $sql->select('p.weight, p.width, p.height, p.depth, ps.additional_shipping_cost');
        $sql->select('p.date_add, p.date_upd');
        $sql->from('product', 'p');
        $sql->innerJoin('product_shop', 'ps', 'ps.id_product = p.id_product AND ps.id_shop = ' . (int)$idShop);
        $sql->leftJoin('product_lang', 'pl', 'pl.id_product = p.id_product AND pl.id_shop = ' . (int)$idShop . ' AND pl.id_lang = ' . (int)$idLang);
        $sql->leftJoin('manufacturer', 'm', 'm.id_manufacturer = p.id_manufacturer');
        $sql->leftJoin('supplier', 's', 's.id_supplier = p.id_supplier');
        $sql->leftJoin('stock_available', 'sa', 'sa.id_product = p.id_product AND sa.id_product_attribute = 0 AND sa.id_shop = ' . (int)$idShop);

        // Filtry
        if (!empty($filters['only_active'])) {
            $sql->where('ps.active = 1');
        }
        if (!empty($filters['only_available'])) {
            $sql->where('sa.quantity > 0');
        }
        if (!empty($filters['categories'])) {
            $ids = array_map('intval', (array)$filters['categories']);
            $sql->innerJoin('category_product', 'cp', 'cp.id_product = p.id_product AND cp.id_category IN (' . implode(',', $ids) . ')');
        }
        if (!empty($filters['manufacturers'])) {
            $ids = array_map('intval', (array)$filters['manufacturers']);
            $sql->where('p.id_manufacturer IN (' . implode(',', $ids) . ')');
        }
        if (!empty($filters['min_price'])) {
            $sql->where('ps.price >= ' . (float)$filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $sql->where('ps.price <= ' . (float)$filters['max_price']);
        }

        $sql->orderBy('p.id_product ASC');
        $sql->limit((int)$limit, (int)$offset);

        $rows = $db->executeS($sql);
        if (!$rows) {
            return [];
        }

        $products = [];
        foreach ($rows as $r) {
            $dto = new ProductDto();
            $dto->id_product = (int)$r['id_product'];
            $dto->name = (string)$r['name'];
            $dto->slug = (string)$r['slug'];
            $dto->description_short = (string)$r['description_short'];
            $dto->description = (string)$r['description'];
            $dto->meta_title = (string)$r['meta_title'];
            $dto->meta_description = (string)$r['meta_description'];
            $dto->reference = (string)$r['reference'];
            $dto->supplier_reference = (string)$r['supplier_reference'];
            $dto->ean13 = (string)$r['ean13'];
            $dto->mpn = (string)$r['mpn'];
            $dto->isbn = (string)$r['isbn'];
            $dto->upc = (string)$r['upc'];
            $dto->manufacturer_name = (string)$r['manufacturer_name'];
            $dto->supplier_name = (string)$r['supplier_name'];
            $dto->price_tax_excl = (float)$r['price_tax_excl'];
            $dto->tax_rate = self::getTaxRate($r['id_tax_rules_group'], $idShop);
            $dto->price_tax_incl = $dto->price_tax_excl * (1 + $dto->tax_rate / 100);
            $dto->quantity = (int)$r['quantity'];
            $dto->available_for_order = (bool)$r['available_for_order'];
            $dto->minimal_quantity = (int)$r['minimal_quantity'];
            $dto->weight = (float)$r['weight'];
            $dto->width = (float)$r['width'];
            $dto->height = (float)$r['height'];
            $dto->depth = (float)$r['depth'];
            $dto->additional_shipping_cost = (float)$r['additional_shipping_cost'];
            $dto->condition = (string)$r['condition'];
            $dto->visibility = (string)$r['visibility'];
            $dto->online_only = (bool)$r['online_only'];
            $dto->date_add = (string)$r['date_add'];
            $dto->date_upd = (string)$r['date_upd'];
            $dto->currency = self::getCurrencyIso($idShop);

            // Linki
            $dto->product_url = Context::getContext()->link->getProductLink((int)$r['id_product']);
            $dto->manufacturer_url = $r['manufacturer_name']
                ? Context::getContext()->link->getManufacturerLink((int)Manufacturer::getIdByName($r['manufacturer_name']))
                : null;

            // Kategorie, obrazy, cechy
            $dto->categories = self::getCategories($r['id_product'], $idLang);
            $dto->cover_image = self::getCoverImage($r['id_product'], $idShop);
            $dto->images = self::getAllImages($r['id_product'], $idShop);
            $dto->features = self::getFeatures($r['id_product'], $idLang);

            $products[] = $dto;
        }

        // Warianty – tylko jeśli wymagane
        if ($withCombinations) {
            $products = self::attachCombinations($products, $idShop, $idLang);
        }

        return $products;
    }

    /**
     * Zwraca stawkę podatku (z cache)
     */
    protected static $taxCache = [];

    protected static function getTaxRate($idTaxRulesGroup, $idShop)
    {
        $key = $idTaxRulesGroup . '-' . $idShop;
        if (isset(self::$taxCache[$key])) {
            return self::$taxCache[$key];
        }
        $rate = 0.0;
        $taxRules = TaxRule::getTaxRulesByGroupId((int)$idTaxRulesGroup, (int)$idShop);
        if (is_array($taxRules) && count($taxRules)) {
            $first = reset($taxRules);
            if (!empty($first['rate'])) {
                $rate = (float)$first['rate'];
            }
        }
        self::$taxCache[$key] = $rate;
        return $rate;
    }

    /**
     * ISO waluty sklepu
     */
    protected static function getCurrencyIso($idShop)
    {
        $shop = new Shop((int)$idShop);
        $idCurrency = (int)$shop->id_currency;
        $currency = new Currency($idCurrency);
        return (string)$currency->iso_code;
    }

    /**
     * Kategorie produktu
     */
    public static function getCategories($idProduct, $idLang)
    {
        $sql = new DbQuery();
        $sql->select('c.id_category, cl.name');
        $sql->from('category_product', 'cp');
        $sql->innerJoin('category', 'c', 'c.id_category = cp.id_category');
        $sql->leftJoin('category_lang', 'cl', 'cl.id_category = c.id_category AND cl.id_lang = ' . (int)$idLang);
        $sql->where('cp.id_product = ' . (int)$idProduct);
        $sql->orderBy('c.level_depth ASC');
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
    }

    /**
     * Cechy produktu
     */
    public static function getFeatures($idProduct, $idLang)
    {
        $sql = new DbQuery();
        $sql->select('f.id_feature, fl.name AS feature_name, fvl.value AS feature_value');
        $sql->from('feature_product', 'fp');
        $sql->innerJoin('feature', 'f', 'f.id_feature = fp.id_feature');
        $sql->leftJoin('feature_lang', 'fl', 'fl.id_feature = f.id_feature AND fl.id_lang = ' . (int)$idLang);
        $sql->leftJoin('feature_value_lang', 'fvl', 'fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = ' . (int)$idLang);
        $sql->where('fp.id_product = ' . (int)$idProduct);
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $features = [];
        foreach ($rows as $r) {
            $features[] = [
                'id' => (int)$r['id_feature'],
                'key' => Tools::link_rewrite($r['feature_name']),
                'name' => $r['feature_name'],
                'value' => $r['feature_value'],
            ];
        }
        return $features;
    }

    /**
     * Zdjęcie główne
     */
    public static function getCoverImage($idProduct, $idShop)
    {
        $idImage = Db::getInstance()->getValue('
            SELECT i.id_image FROM ' . _DB_PREFIX_ . 'image i
            INNER JOIN ' . _DB_PREFIX_ . 'image_shop ish ON (ish.id_image = i.id_image AND ish.id_shop = ' . (int)$idShop . ')
            WHERE i.id_product = ' . (int)$idProduct . ' AND i.cover = 1
        ');
        if ($idImage) {
            return Context::getContext()->link->getImageLink('p', $idImage, ImageType::getFormattedName('large'));
        }
        return null;
    }

    /**
     * Wszystkie obrazy produktu
     */
    public static function getAllImages($idProduct, $idShop)
    {
        $sql = new DbQuery();
        $sql->select('i.id_image');
        $sql->from('image', 'i');
        $sql->innerJoin('image_shop', 'ish', 'ish.id_image = i.id_image AND ish.id_shop = ' . (int)$idShop);
        $sql->where('i.id_product = ' . (int)$idProduct);
        $sql->orderBy('i.position ASC');
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $images = [];
        foreach ($rows as $r) {
            $images[] = Context::getContext()->link->getImageLink('p', $r['id_image'], ImageType::getFormattedName('large'));
        }
        return $images;
    }

    /**
     * Dokleja kombinacje do listy produktów
     */
    public static function attachCombinations(array $products, $idShop, $idLang)
    {
        foreach ($products as $dto) {
            $dto->attributes = self::getCombinations($dto->id_product, $idShop, $idLang);
        }
        return $products;
    }

    /**
     * Kombinacje (atrybuty wariantów)
     */
    public static function getCombinations($idProduct, $idShop, $idLang)
    {
        $sql = new DbQuery();
        $sql->select('pa.id_product_attribute, agl.name AS group_name, al.name AS attr_name');
        $sql->from('product_attribute', 'pa');
        $sql->innerJoin('product_attribute_combination', 'pac', 'pac.id_product_attribute = pa.id_product_attribute');
        $sql->innerJoin('attribute', 'a', 'a.id_attribute = pac.id_attribute');
        $sql->leftJoin('attribute_lang', 'al', 'al.id_attribute = a.id_attribute AND al.id_lang = ' . (int)$idLang);
        $sql->leftJoin('attribute_group_lang', 'agl', 'agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . (int)$idLang);
        $sql->where('pa.id_product = ' . (int)$idProduct);
        $sql->orderBy('pa.id_product_attribute, agl.name');
        $rows = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);
        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id_product_attribute' => (int)$r['id_product_attribute'],
                'group' => $r['group_name'],
                'name'  => $r['attr_name'],
            ];
        }
        return $result;
    }
}
