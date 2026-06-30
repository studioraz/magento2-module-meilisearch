<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchBase\Model\Indexer;

use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\Framework\Indexer\SaveHandler\IndexerInterface;
use Magento\Framework\Search\Request\Dimension;
use Walkwizus\MeilisearchBase\Model\AttributeMapper;
use Walkwizus\MeilisearchBase\Model\AttributeProvider;
use Walkwizus\MeilisearchBase\SearchAdapter\SearchIndexNameResolver;
use Walkwizus\MeilisearchBase\Service\DocumentsManager;
use Walkwizus\MeilisearchBase\Service\HealthManager;
use Walkwizus\MeilisearchBase\Service\IndexesManager;
use Walkwizus\MeilisearchBase\Service\SettingsManager;

class BaseIndexerHandler implements IndexerInterface
{
    private const INDEX_SWAP_SUFFIX = 'tmp';

    private bool $isFullReindex = false;

    /**
     * @var array<string, string>
     */
    private array $temporaryIndexes = [];

    public function __construct(
        private readonly SettingsManager $settingsManager,
        private readonly IndexesManager $indexesManager,
        private readonly DocumentsManager $documentsManager,
        private readonly HealthManager $healthManager,
        private readonly SearchIndexNameResolver $searchIndexNameResolver,
        private readonly Batch $batch,
        private readonly string $indexerId,
        private readonly AttributeMapper $attributeMapper,
        private readonly AttributeProvider $attributeProvider,
        private readonly int $batchSize = 10000,
        private readonly string $indexPrimaryKey = 'id',
        private readonly array $preProcessors = []
    ) {
    }

    /**
     * @param Dimension[] $dimensions
     * @throws \Exception
     */
    public function saveIndex($dimensions, \Traversable $documents): IndexerInterface
    {
        try {
            foreach ($dimensions as $dimension) {
                $storeId = (int)$dimension->getValue();
                $indexerId = $this->getIndexerId();
                $indexName = $this->searchIndexNameResolver->getIndexName($storeId, $indexerId);
                $targetIndexName = $this->isFullReindex ? $indexName . '_' . self::INDEX_SWAP_SUFFIX : $indexName;

                $this->settingsManager->updateFilterableAttributes(
                    $targetIndexName,
                    $this->attributeProvider->getFilterableAttributes($indexerId, 'index')
                );
                $this->settingsManager->updateSortableAttributes(
                    $targetIndexName,
                    $this->attributeProvider->getSortableAttributes($indexerId, 'index')
                );
                $this->settingsManager->updateSearchableAttributes(
                    $targetIndexName,
                    $this->attributeProvider->getSearchableAttributes($indexerId, 'index')
                );

                foreach ($this->batch->getItems($documents, $this->batchSize) as $batchDocuments) {
                    $context = [];

                    if (isset($this->preProcessors[$indexerId])) {
                        $context = $this->preProcessors[$indexerId]->prepare($indexerId, $batchDocuments, $storeId);
                    }

                    $batchDocuments = $this->attributeMapper->map($indexerId, $batchDocuments, $storeId, $context);
                    if ($batchDocuments === []) {
                        continue;
                    }

                    $this->documentsManager->addDocumentsInBatches(
                        $targetIndexName,
                        $batchDocuments,
                        $this->indexPrimaryKey
                    );
                }
            }

            if ($this->isFullReindex && !empty($this->temporaryIndexes)) {
                $this->performSwap();
            }
        } catch (\Throwable $exception) {
            $this->resetFullReindexState();
            throw $exception;
        }

        return $this;
    }

    /**
     * @param Dimension[] $dimensions
     * @throws \Exception
     */
    public function deleteIndex($dimensions, \Traversable $documents): IndexerInterface
    {
        foreach ($dimensions as $dimension) {
            $storeId = (int)$dimension->getValue();
            $indexerId = $this->getIndexerId();
            $indexName = $this->searchIndexNameResolver->getIndexName($storeId, $indexerId);

            $documentIds = [];
            foreach ($documents as $document) {
                if ($document !== null && $document !== '') {
                    $documentIds[] = $document;
                }
            }

            if ($documentIds !== []) {
                $this->documentsManager->deleteDocuments($indexName, $documentIds);
            }
        }

        return $this;
    }

    /**
     * @param Dimension[] $dimensions
     * @throws \Exception
     */
    public function cleanIndex($dimensions): IndexerInterface
    {
        $this->isFullReindex = true;

        try {
            foreach ($dimensions as $dimension) {
                $storeId = (int)$dimension->getValue();
                $indexerId = $this->getIndexerId();
                $indexName = $this->searchIndexNameResolver->getIndexName($storeId, $indexerId);
                $tmpIndexName = $indexName . '_' . self::INDEX_SWAP_SUFFIX;

                $this->temporaryIndexes[$indexName] = $tmpIndexName;

                if (!$this->indexesManager->indexExists($indexName)) {
                    $this->indexesManager->createIndex($indexName, $this->indexPrimaryKey);
                }

                if ($this->indexesManager->indexExists($tmpIndexName)) {
                    $this->indexesManager->deleteIndex($tmpIndexName);
                }
            }
        } catch (\Throwable $exception) {
            $this->resetFullReindexState();
            throw $exception;
        }

        return $this;
    }

    /**
     * @param Dimension[] $dimensions
     */
    public function isAvailable($dimensions = []): bool
    {
        try {
            return $this->healthManager->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getIndexerId(): string
    {
        return $this->searchIndexNameResolver->getIndexMapping($this->indexerId);
    }

    /**
     * @throws \Exception
     */
    private function performSwap(): void
    {
        $swaps = [];
        foreach ($this->temporaryIndexes as $realIndex => $tmpIndex) {
            $swaps[] = [$realIndex, $tmpIndex];
        }

        try {
            $this->indexesManager->swapIndexes($swaps);

            foreach ($this->temporaryIndexes as $tmpIndex) {
                if ($this->indexesManager->indexExists($tmpIndex)) {
                    $this->indexesManager->deleteIndex($tmpIndex);
                }
            }
        } finally {
            $this->resetFullReindexState();
        }
    }

    private function resetFullReindexState(): void
    {
        $this->isFullReindex = false;
        $this->temporaryIndexes = [];
    }
}
