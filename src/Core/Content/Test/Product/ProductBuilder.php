<?php declare(strict_types=1);

namespace Shopware\Core\Content\Test\Product;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\IdsCollection;

/**
 * How to use:
 *
 * $x = (new ProductBuilder(new IdsCollection(), 'p1'))
 *          ->price(Defaults::CURRENCY, 100)
 *          ->prices(Defaults::CURRENCY, 'rule-1', 100)
 *          ->manufacturer('m1')
 *          ->build();
 */
class ProductBuilder
{
    /**
     * @var IdsCollection
     */
    protected $ids;

    /**
     * @var string
     */
    protected $productNumber;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string|null
     */
    protected $name;

    /**
     * @var array|null
     */
    protected $manufacturer;

    /**
     * @var array|null
     */
    protected $tax;

    /**
     * @var bool
     */
    protected $active = true;

    /**
     * @var array
     */
    protected $price = [];

    /**
     * @var array
     */
    protected $prices = [];

    /**
     * @var array
     */
    protected $categories = [];

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var int
     */
    protected $stock;

    /**
     * @var string|null
     */
    protected $releaseDate;

    /**
     * @var array
     */
    protected $customFields = [];

    /**
     * @var array
     */
    protected $visibilities = [];

    /**
     * @var array|null
     */
    protected $purchasePrices;

    /**
     * @var float|null
     */
    protected $purchasePrice;

    /**
     * @var string|null
     */
    protected $parentId;

    /**
     * @var array
     */
    protected $_dynamic = [];

    /**
     * @var array[]
     */
    protected $children = [];

    public function __construct(IdsCollection $ids, string $number, int $stock = 1, string $taxKey = 't1')
    {
        $this->ids = $ids;
        $this->productNumber = $number;
        $this->id = $this->ids->create($number);
        $this->stock = $stock;
        $this->name = $number;
        $this->tax($taxKey);
    }

    public function parent(string $key): self
    {
        $this->parentId = $this->ids->get($key);

        return $this;
    }

    public function name(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function tax(?string $key, int $rate = 15): self
    {
        if ($key === null) {
            $this->tax = null;

            return $this;
        }

        $this->tax = [
            'id' => $this->ids->create($key),
            'name' => 'test',
            'taxRate' => $rate,
        ];

        return $this;
    }

    public function variant(array $data): ProductBuilder
    {
        $this->children[] = $data;

        return $this;
    }

    public function manufacturer(string $key): self
    {
        $this->manufacturer = [
            'id' => $this->ids->create($key),
            'name' => $key,
        ];

        return $this;
    }

    public function releaseDate(string $releaseDate): self
    {
        $this->releaseDate = $releaseDate;

        return $this;
    }

    public function visibility(string $salesChannelId = Defaults::SALES_CHANNEL, int $visibility = ProductVisibilityDefinition::VISIBILITY_ALL): self
    {
        $this->visibilities[$salesChannelId] = ['salesChannelId' => $salesChannelId, 'visibility' => $visibility];

        return $this;
    }

    public function purchasePrice(float $price): self
    {
        $this->purchasePrice = $price;
        $this->purchasePrices[] = ['currencyId' => Defaults::CURRENCY, 'gross' => $price, 'net' => $price / 115 * 100, 'linked' => false];

        return $this;
    }

    public function price(float $gross, ?float $net = null, string $currencyKey = 'default'): self
    {
        $net = $net ?? $gross / 115 * 100;

        $price = [
            'gross' => $gross,
            'net' => $net,
            'linked' => false,
        ];

        $price = $this->buildCurrencyPrice($currencyKey, $price);

        $this->price[$currencyKey] = $price;

        return $this;
    }

    public function prices(string $ruleKey, float $gross, string $currencyKey = 'default', ?float $net = null, int $start = 1): self
    {
        $net = $net ?? $gross / 115 * 100;

        $ruleId = $this->ids->create($ruleKey);

        // add to existing price - if exists
        foreach ($this->prices as &$price) {
            if ($price['rule']['id'] !== $ruleId) {
                continue;
            }
            if ($price['quantityStart'] !== $start) {
                continue;
            }

            $raw = ['gross' => $gross, 'net' => $net, 'linked' => false];

            $price['price'][] = $this->buildCurrencyPrice($currencyKey, $raw);

            return $this;
        }

        unset($price);

        $price = ['gross' => $gross, 'net' => $net, 'linked' => false];

        $this->prices[] = [
            'quantityStart' => $start,
            'rule' => [
                'id' => $this->ids->create($ruleKey),
                'priority' => 1,
                'name' => 'test',
            ],
            'price' => [
                $this->buildCurrencyPrice($currencyKey, $price),
            ],
        ];

        return $this;
    }

    public function category(string $key): ProductBuilder
    {
        $this->categories[] = ['id' => $this->ids->create($key), 'name' => $key];

        return $this;
    }

    /**
     * @param array|object|string|float|int|bool|null $value
     */
    public function customField(string $key, $value): ProductBuilder
    {
        $this->customFields[$key] = $value;

        return $this;
    }

    /**
     * @param array|object|string|float|int|bool|null $value
     */
    public function add(string $key, $value): ProductBuilder
    {
        $this->_dynamic[$key] = $value;

        return $this;
    }

    public function build(): array
    {
        $this->fixPricesQuantity();

        $data = get_object_vars($this);

        unset($data['ids'], $data['_dynamic']);

        $data = array_merge($data, $this->_dynamic);

        return array_filter($data);
    }

    public function property(string $key, string $group): ProductBuilder
    {
        $this->properties[] = [
            'id' => $this->ids->get($key),
            'name' => $key,
            'group' => [
                'id' => $this->ids->get($group),
                'name' => $group,
            ],
        ];

        return $this;
    }

    public function stock(int $stock): ProductBuilder
    {
        $this->stock = $stock;

        return $this;
    }

    public function active(bool $active): void
    {
        $this->active = $active;
    }

    private function fixPricesQuantity(): void
    {
        $grouped = [];
        foreach ($this->prices as $price) {
            $grouped[$price['rule']['id']][] = $price;
        }

        foreach ($grouped as &$group) {
            usort($group, function (array $a, array $b) {
                return $a['quantityStart'] <=> $b['quantityStart'];
            });
        }

        $mapped = [];
        foreach ($grouped as &$group) {
            $group = array_reverse($group);

            $end = null;
            foreach ($group as $price) {
                if ($end !== null) {
                    $price['quantityEnd'] = $end;
                }

                $end = $price['quantityStart'] - 1;

                $mapped[] = $price;
            }
        }

        $this->prices = array_reverse($mapped);
    }

    private function buildCurrencyPrice(string $currencyKey, array $price): array
    {
        if ($currencyKey === 'default') {
            $price['currencyId'] = Defaults::CURRENCY;

            return $price;
        }

        if ($this->ids->has($currencyKey)) {
            $price['currencyId'] = $this->ids->get($currencyKey);

            return $price;
        }

        $price['currency'] = [
            'id' => $this->ids->get($currencyKey),
            'factor' => 2,
            'name' => 'test-currency',
            'shortName' => 'TC',
            'symbol' => '$',
            'isoCode' => 'en-GB',
            'decimalPrecision' => 3,
        ];

        return $price;
    }
}
