<?php

declare(strict_types=1);

namespace Walkwizus\MeilisearchCatalog\Model\Indexer;

class ReplayableDocuments
{
    private \SplTempFileObject $storage;

    private bool $sourceConsumed = false;

    public function __construct(
        private readonly \Traversable $source,
        int $memoryLimit = 2097152
    ) {
        $this->storage = new \SplTempFileObject($memoryLimit);
    }

    public function getIterator(): \Traversable
    {
        if (!$this->sourceConsumed) {
            foreach ($this->source as $key => $document) {
                $this->write($key, $document);
                yield $key => $document;
            }

            $this->sourceConsumed = true;
            return;
        }

        $this->storage->rewind();
        while (!$this->storage->eof()) {
            $line = $this->storage->fgets();
            if ($line === false) {
                continue;
            }

            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            [$key, $document] = unserialize(base64_decode($line), ['allowed_classes' => false]);
            yield $key => $document;
        }
    }

    public function close(): void
    {
        unset($this->storage);
    }

    private function write(mixed $key, mixed $document): void
    {
        $this->storage->fwrite(base64_encode(serialize([$key, $document])) . PHP_EOL);
    }
}
