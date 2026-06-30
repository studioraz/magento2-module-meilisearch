<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchCatalog\Model\Indexer;

use Magento\Elasticsearch\Model\Indexer\IndexerHandler as OpenSearchIndexerHandler;
use Magento\Framework\Indexer\SaveHandler\IndexerInterface;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

class CompositeIndexerHandler implements IndexerInterface
{
    private IndexerInterface $primaryIndexerHandler;

    public function __construct(
        ObjectManagerInterface $objectManager,
        private readonly IndexerInterface $mirrorIndexerHandler,
        private readonly LoggerInterface $logger,
        private readonly array $data = [],
        private readonly string $primaryHandlerClass = OpenSearchIndexerHandler::class,
        private readonly bool $softFailMirror = true
    ) {
        $this->primaryIndexerHandler = $objectManager->create($this->primaryHandlerClass, ['data' => $this->data]);
    }

    public function saveIndex($dimensions, \Traversable $documents): IndexerInterface
    {
        $replayableDocuments = new ReplayableDocuments($documents);

        try {
            $this->primaryIndexerHandler->saveIndex($dimensions, $replayableDocuments->getIterator());
            $this->runMirror(
                static fn (IndexerInterface $mirrorIndexerHandler) => $mirrorIndexerHandler->saveIndex(
                    $dimensions,
                    $replayableDocuments->getIterator()
                ),
                'saveIndex'
            );
        } finally {
            $replayableDocuments->close();
        }

        return $this;
    }

    public function deleteIndex($dimensions, \Traversable $documents): IndexerInterface
    {
        $replayableDocuments = new ReplayableDocuments($documents);

        try {
            $this->primaryIndexerHandler->deleteIndex($dimensions, $replayableDocuments->getIterator());
            $this->runMirror(
                static fn (IndexerInterface $mirrorIndexerHandler) => $mirrorIndexerHandler->deleteIndex(
                    $dimensions,
                    $replayableDocuments->getIterator()
                ),
                'deleteIndex'
            );
        } finally {
            $replayableDocuments->close();
        }

        return $this;
    }

    public function cleanIndex($dimensions): IndexerInterface
    {
        $this->primaryIndexerHandler->cleanIndex($dimensions);
        $this->runMirror(
            static fn (IndexerInterface $mirrorIndexerHandler) => $mirrorIndexerHandler->cleanIndex($dimensions),
            'cleanIndex'
        );

        return $this;
    }

    public function isAvailable($dimensions = []): bool
    {
        return $this->primaryIndexerHandler->isAvailable($dimensions);
    }

    private function runMirror(callable $callback, string $operation): void
    {
        try {
            if (!$this->mirrorIndexerHandler->isAvailable()) {
                throw new \RuntimeException('Meilisearch is not available.');
            }

            $callback($this->mirrorIndexerHandler);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('Meilisearch mirror %s failed during catalogsearch_fulltext reindex.', $operation),
                ['exception' => $exception]
            );

            if (!$this->softFailMirror) {
                throw $exception;
            }
        }
    }
}
