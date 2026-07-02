# Walkwizus_MeilisearchCatalog

Adds catalog product indexing support for Meilisearch, including attribute providers, mappers, and indexer wiring.

**Plug In**
- Add custom product attributes by registering new providers in the `providers` argument of `Walkwizus\MeilisearchBase\Model\AttributeProvider` under `catalog_product` and context `index`.
- Add custom document mapping by registering new mappers in the `mappers` argument of `Walkwizus\MeilisearchBase\Model\AttributeMapper` under `catalog_product`.
- If you add new indexers, use `Walkwizus\MeilisearchBase\Model\Indexer\BaseIndexerHandler` similar to the virtual types in `src/module-meilisearch-catalog/etc/di.xml`.
