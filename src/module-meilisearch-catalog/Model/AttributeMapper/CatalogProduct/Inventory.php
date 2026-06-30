<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchCatalog\Model\AttributeMapper\CatalogProduct;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Walkwizus\MeilisearchBase\Api\AttributeMapperInterface;

class Inventory implements AttributeMapperInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function map(array $documentData, $storeId, array $context = []): array
    {
        $productIds = array_map('intval', array_keys($documentData));
        if ($productIds === []) {
            return [];
        }

        $indexData = [];
        foreach ($this->loadInventoryData((int)$storeId, $productIds) as $inventoryDatum) {
            $productId = (int)$inventoryDatum['product_id'];
            $indexData[$productId]['stock'] = [
                'is_in_stock' => (bool)$inventoryDatum['stock_status'],
                'qty' => (float)$inventoryDatum['qty'],
            ];
        }

        return $indexData;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function loadInventoryData(int $storeId, array $productIds): array
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $connection = $this->resource->getConnection();

        $websiteIds = array_values(array_unique([$websiteId, 0]));
        $select = $connection->select()
            ->from(
                ['stock_status' => $connection->getTableName('cataloginventory_stock_status')],
                ['product_id', 'stock_status', 'qty']
            )
            ->where('stock_status.website_id IN (?)', $websiteIds)
            ->where('stock_status.product_id IN (?)', $productIds)
            ->order('stock_status.website_id ASC');

        return $connection->fetchAll($select);
    }
}
