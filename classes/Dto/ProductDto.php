<?php
/**
 * classes/Dto/ProductDto.php
 * Reprezentacja danych produktu dla eksportu XML (DTO)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductDto
{
    /** @var int */
    public $id_product;

    /** @var int|null */
    public $id_product_attribute;

    /** @var string */
    public $reference;

    /** @var string|null */
    public $supplier_reference;

    /** @var string|null */
    public $ean13;

    /** @var string|null */
    public $mpn;

    /** @var string|null */
    public $isbn;

    /** @var string|null */
    public $upc;

    /** @var string */
    public $slug;

    /** @var string */
    public $name;

    /** @var string */
    public $description_short;

    /** @var string */
    public $description;

    /** @var string */
    public $meta_title;

    /** @var string */
    public $meta_description;

    /** @var string */
    public $manufacturer_name;

    /** @var string|null */
    public $supplier_name;

    /** @var float */
    public $price_tax_excl;

    /** @var float */
    public $price_tax_incl;

    /** @var float|null */
    public $promo_price_tax_incl;

    /** @var float|null */
    public $tax_rate;

    /** @var string|null */
    public $promo_from;

    /** @var string|null */
    public $promo_to;

    /** @var string|null */
    public $currency;

    /** @var int */
    public $quantity;

    /** @var bool */
    public $available_for_order;

    /** @var int */
    public $minimal_quantity;

    /** @var string|null */
    public $availability_label;

    /** @var float|null */
    public $weight;

    /** @var float|null */
    public $width;

    /** @var float|null */
    public $height;

    /** @var float|null */
    public $depth;

    /** @var float|null */
    public $additional_shipping_cost;

    /** @var string|null */
    public $cover_image;

    /** @var array */
    public $images = [];

    /** @var array */
    public $categories = [];

    /** @var string|null */
    public $main_category;

    /** @var string|null */
    public $category_path;

    /** @var array */
    public $features = []; // [ [ 'id' => 12, 'key' => 'color', 'name' => 'Kolor', 'value' => 'Czerwony' ], ...]

    /** @var array */
    public $attributes = []; // tylko jeśli kombinacja

    /** @var string */
    public $product_url;

    /** @var string|null */
    public $category_url;

    /** @var string|null */
    public $manufacturer_url;

    /** @var string */
    public $condition;

    /** @var string */
    public $visibility;

    /** @var bool */
    public $online_only;

    /** @var float|null */
    public $unit_price;

    /** @var string|null */
    public $unit;

    /** @var bool */
    public $is_virtual;

    /** @var string|null */
    public $date_add;

    /** @var string|null */
    public $date_upd;

    /** @var string|null */
    public $last_price_change;

    /** @var array */
    public $tags = [];

    /** @var array */
    public $attachments = [];

    /**
     * Zwraca dane jako tablicę (pomocne przy testach / JSON podglądzie)
     */
    public function toArray()
    {
        return get_object_vars($this);
    }

    /**
     * Zwraca prosty opis identyfikujący produkt (np. do logów)
     */
    public function getLabel()
    {
        return sprintf(
            '#%d%s %s',
            (int)$this->id_product,
            $this->id_product_attribute ? ('/' . (int)$this->id_product_attribute) : '',
            $this->name
        );
    }

    /**
     * Pomocnicza metoda tworząca DTO z tablicy danych (np. z DbQuery)
     */
    public static function fromArray(array $data)
    {
        $obj = new self();
        foreach ($data as $key => $val) {
            if (property_exists($obj, $key)) {
                $obj->$key = $val;
            }
        }
        return $obj;
    }
}
