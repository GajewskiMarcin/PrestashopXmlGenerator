<?php
/**
 * classes/Dto/FeatureDto.php
 * Reprezentacja cechy produktu (feature) do eksportu XML
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class FeatureDto
{
    /** @var int */
    public $id_feature;

    /** @var string */
    public $key;

    /** @var string */
    public $name;

    /** @var string */
    public $value;

    /**
     * Konstruktor DTO
     */
    public function __construct($id_feature = null, $key = null, $name = null, $value = null)
    {
        $this->id_feature = (int)$id_feature;
        $this->key = (string)$key;
        $this->name = (string)$name;
        $this->value = (string)$value;
    }

    /**
     * Tworzy DTO z tablicy asocjacyjnej
     */
    public static function fromArray(array $data)
    {
        $dto = new self();
        foreach ($data as $k => $v) {
            if (property_exists($dto, $k)) {
                $dto->$k = $v;
            }
        }
        return $dto;
    }

    /**
     * Zwraca dane w formacie tablicy (np. do JSON)
     */
    public function toArray()
    {
        return [
            'id_feature' => (int)$this->id_feature,
            'key'        => (string)$this->key,
            'name'       => (string)$this->name,
            'value'      => (string)$this->value,
        ];
    }

    /**
     * Zwraca prostą reprezentację tekstową np. "Kolor: Czerwony"
     */
    public function __toString()
    {
        return sprintf('%s: %s', $this->name ?: $this->key, $this->value);
    }
}
