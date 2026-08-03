<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot\Indexing;

use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\Indexing\Exception\IndexingException;
use AIKnowledgeChatbot\Indexing\Extractors\CsvExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\DocxExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\FaqExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\PdfExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\PostExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\TxtExtractor;
use AIKnowledgeChatbot\Indexing\Extractors\WooCommerceProductExtractor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central lookup for every registered knowledge source extractor, mirroring
 * AI\Provider\ProviderRegistry's pattern. Adding a new source type means
 * writing a class that implements SourceExtractorInterface and adding it
 * to $defaults, or registering it from a separate add-on via the
 * `aikc_register_extractors` filter — nothing else in the plugin changes.
 */
final class ExtractorRegistry
{
    /** @var array<string, SourceExtractorInterface> */
    private array $extractors = [];

    public function __construct(SettingsRepository $settings)
    {
        $defaults = [
            new PostExtractor($settings),
            new WooCommerceProductExtractor($settings),
            new FaqExtractor(),
            new TxtExtractor(),
            new CsvExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
        ];

        /**
         * Filters the list of registered source extractors.
         *
         * @param SourceExtractorInterface[] $defaults
         */
        $instances = apply_filters('aikc_register_extractors', $defaults);

        foreach ($instances as $extractor) {
            if ($extractor instanceof SourceExtractorInterface) {
                $this->extractors[$extractor->getType()] = $extractor;
            }
        }
    }

    public function has(string $type): bool
    {
        return isset($this->extractors[$type]);
    }

    public function get(string $type): SourceExtractorInterface
    {
        if (!$this->has($type)) {
            throw new IndexingException(sprintf('Unknown source type "%s".', $type));
        }

        return $this->extractors[$type];
    }

    /**
     * @return SourceExtractorInterface[]
     */
    public function all(): array
    {
        return $this->extractors;
    }
}
