<?php
declare(strict_types=1);

namespace AIKnowledgeChatbot;

use AIKnowledgeChatbot\Admin\SettingsPage;
use AIKnowledgeChatbot\Admin\Settings\ApiKeyEncryptor;
use AIKnowledgeChatbot\Admin\Settings\SettingsRepository;
use AIKnowledgeChatbot\AI\Provider\ProviderRegistry;
use AIKnowledgeChatbot\Analytics\Admin\AnalyticsPage;
use AIKnowledgeChatbot\Analytics\ChatLogRepository;
use AIKnowledgeChatbot\Analytics\LogRetentionScheduler;
use AIKnowledgeChatbot\Chat\ChatService;
use AIKnowledgeChatbot\Chat\Frontend\ChatWidget;
use AIKnowledgeChatbot\Chat\PromptBuilder;
use AIKnowledgeChatbot\Chat\RateLimiter;
use AIKnowledgeChatbot\Chat\ResponseCache;
use AIKnowledgeChatbot\Chat\Rest\ChatRestController;
use AIKnowledgeChatbot\Embedding\EmbeddingProviderRegistry;
use AIKnowledgeChatbot\Indexing\Admin\KnowledgeManagerPage;
use AIKnowledgeChatbot\Indexing\Admin\UploadHandler;
use AIKnowledgeChatbot\Indexing\Chunker;
use AIKnowledgeChatbot\Indexing\ExtractorRegistry;
use AIKnowledgeChatbot\Indexing\HtmlCleaner;
use AIKnowledgeChatbot\Indexing\IndexingService;
use AIKnowledgeChatbot\Indexing\IndexRepository;
use AIKnowledgeChatbot\Indexing\PostTypes\FaqPostType;
use AIKnowledgeChatbot\Indexing\PromptInjectionSanitizer;
use AIKnowledgeChatbot\Indexing\ReindexScheduler;
use AIKnowledgeChatbot\Indexing\Schema;
use AIKnowledgeChatbot\Retrieval\ConfiguredResolver;
use AIKnowledgeChatbot\Retrieval\EmbeddingQueueScheduler;
use AIKnowledgeChatbot\Retrieval\EmbeddingWorker;
use AIKnowledgeChatbot\Retrieval\RetrievalService;
use AIKnowledgeChatbot\Security\Capabilities;
use AIKnowledgeChatbot\Security\ClientIpResolver;
use AIKnowledgeChatbot\VectorStore\VectorStoreRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin bootstrap and service locator root.
 *
 * Responsible only for wiring the DI container and hooking the plugin into
 * WordPress. Feature logic lives in dedicated service classes — this class
 * should never grow business logic of its own.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Container $container;

    private bool $booted = false;

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Wires services and hooks into WordPress. Idempotent — safe to call
     * more than once per request.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->registerServices();
        $this->registerHooks();

        $this->booted = true;
    }

    private function registerServices(): void
    {
        // --- Settings & AI provider services (Phase 1) ---

        $this->container->set(SettingsRepository::class, static function (): SettingsRepository {
            return new SettingsRepository();
        });

        $this->container->set(ApiKeyEncryptor::class, static function (): ApiKeyEncryptor {
            return new ApiKeyEncryptor();
        });

        $this->container->set(ProviderRegistry::class, static function (): ProviderRegistry {
            return new ProviderRegistry();
        });

        $this->container->set(EmbeddingProviderRegistry::class, static function (): EmbeddingProviderRegistry {
            return new EmbeddingProviderRegistry();
        });

        $this->container->set(VectorStoreRegistry::class, static function (): VectorStoreRegistry {
            return new VectorStoreRegistry();
        });

        $this->container->set(SettingsPage::class, static function (Container $c): SettingsPage {
            return new SettingsPage(
                $c->get(SettingsRepository::class),
                $c->get(ApiKeyEncryptor::class),
                $c->get(ProviderRegistry::class),
                $c->get(EmbeddingProviderRegistry::class),
                $c->get(VectorStoreRegistry::class)
            );
        });

        // --- Knowledge indexing services (Phase 2) ---

        $this->container->set(FaqPostType::class, static function (): FaqPostType {
            return new FaqPostType();
        });

        $this->container->set(HtmlCleaner::class, static function (): HtmlCleaner {
            return new HtmlCleaner();
        });

        $this->container->set(PromptInjectionSanitizer::class, static function (): PromptInjectionSanitizer {
            return new PromptInjectionSanitizer();
        });

        $this->container->set(Chunker::class, static function (): Chunker {
            return new Chunker();
        });

        $this->container->set(IndexRepository::class, static function (): IndexRepository {
            return new IndexRepository();
        });

        $this->container->set(ExtractorRegistry::class, static function (Container $c): ExtractorRegistry {
            return new ExtractorRegistry($c->get(SettingsRepository::class));
        });

        // --- Retrieval: embeddings + vector store (Phase 3) ---

        $this->container->set(ConfiguredResolver::class, static function (Container $c): ConfiguredResolver {
            return new ConfiguredResolver(
                $c->get(SettingsRepository::class),
                $c->get(ApiKeyEncryptor::class),
                $c->get(ProviderRegistry::class),
                $c->get(EmbeddingProviderRegistry::class),
                $c->get(VectorStoreRegistry::class)
            );
        });

        $this->container->set(RetrievalService::class, static function (Container $c): RetrievalService {
            return new RetrievalService($c->get(ConfiguredResolver::class));
        });

        $this->container->set(EmbeddingWorker::class, static function (Container $c): EmbeddingWorker {
            return new EmbeddingWorker($c->get(ConfiguredResolver::class), $c->get(IndexRepository::class));
        });

        $this->container->set(EmbeddingQueueScheduler::class, static function (Container $c): EmbeddingQueueScheduler {
            return new EmbeddingQueueScheduler($c->get(EmbeddingWorker::class));
        });

        $this->container->set(IndexingService::class, static function (Container $c): IndexingService {
            return new IndexingService(
                $c->get(ExtractorRegistry::class),
                $c->get(IndexRepository::class),
                $c->get(HtmlCleaner::class),
                $c->get(PromptInjectionSanitizer::class),
                $c->get(Chunker::class),
                $c->get(SettingsRepository::class),
                $c->get(ConfiguredResolver::class)
            );
        });

        $this->container->set(ReindexScheduler::class, static function (Container $c): ReindexScheduler {
            return new ReindexScheduler($c->get(IndexingService::class));
        });

        $this->container->set(UploadHandler::class, static function (): UploadHandler {
            return new UploadHandler();
        });

        $this->container->set(KnowledgeManagerPage::class, static function (Container $c): KnowledgeManagerPage {
            return new KnowledgeManagerPage(
                $c->get(SettingsRepository::class),
                $c->get(IndexRepository::class),
                $c->get(IndexingService::class),
                $c->get(UploadHandler::class)
            );
        });

        // --- Chat widget + streaming REST endpoint (Phase 5) ---

        $this->container->set(PromptBuilder::class, static function (): PromptBuilder {
            return new PromptBuilder();
        });

        $this->container->set(ResponseCache::class, static function (Container $c): ResponseCache {
            return new ResponseCache($c->get(SettingsRepository::class));
        });

        $this->container->set(ChatService::class, static function (Container $c): ChatService {
            return new ChatService(
                $c->get(RetrievalService::class),
                $c->get(PromptBuilder::class),
                $c->get(ConfiguredResolver::class),
                $c->get(SettingsRepository::class),
                $c->get(ResponseCache::class)
            );
        });

        $this->container->set(RateLimiter::class, static function (Container $c): RateLimiter {
            return new RateLimiter($c->get(SettingsRepository::class));
        });

        $this->container->set(ChatWidget::class, static function (Container $c): ChatWidget {
            return new ChatWidget($c->get(SettingsRepository::class));
        });

        // --- Analytics, logging, and security hardening (Phase 6) ---

        $this->container->set(ClientIpResolver::class, static function (Container $c): ClientIpResolver {
            return new ClientIpResolver($c->get(SettingsRepository::class));
        });

        $this->container->set(ChatLogRepository::class, static function (): ChatLogRepository {
            return new ChatLogRepository();
        });

        $this->container->set(ChatRestController::class, static function (Container $c): ChatRestController {
            return new ChatRestController(
                $c->get(ChatService::class),
                $c->get(RateLimiter::class),
                $c->get(SettingsRepository::class),
                $c->get(ClientIpResolver::class),
                $c->get(ChatLogRepository::class)
            );
        });

        $this->container->set(LogRetentionScheduler::class, static function (Container $c): LogRetentionScheduler {
            return new LogRetentionScheduler($c->get(ChatLogRepository::class), $c->get(SettingsRepository::class));
        });

        $this->container->set(AnalyticsPage::class, static function (Container $c): AnalyticsPage {
            return new AnalyticsPage(
                $c->get(ChatLogRepository::class),
                $c->get(RateLimiter::class),
                $c->get(ResponseCache::class)
            );
        });
    }

    private function registerHooks(): void
    {
        load_plugin_textdomain(AIKC_TEXT_DOMAIN, false, dirname(AIKC_PLUGIN_BASENAME) . '/languages');

        Capabilities::register();
        Schema::maybeUpgrade();

        /** @var FaqPostType $faqPostType */
        $faqPostType = $this->container->get(FaqPostType::class);
        $faqPostType->register();

        /** @var SettingsPage $settingsPage */
        $settingsPage = $this->container->get(SettingsPage::class);
        $settingsPage->register();

        /** @var KnowledgeManagerPage $knowledgeManagerPage */
        $knowledgeManagerPage = $this->container->get(KnowledgeManagerPage::class);
        $knowledgeManagerPage->register();

        /** @var ReindexScheduler $reindexScheduler */
        $reindexScheduler = $this->container->get(ReindexScheduler::class);
        $reindexScheduler->register();

        /** @var EmbeddingQueueScheduler $embeddingQueueScheduler */
        $embeddingQueueScheduler = $this->container->get(EmbeddingQueueScheduler::class);
        $embeddingQueueScheduler->register();

        /** @var ChatRestController $chatRestController */
        $chatRestController = $this->container->get(ChatRestController::class);
        $chatRestController->register();

        /** @var ChatWidget $chatWidget */
        $chatWidget = $this->container->get(ChatWidget::class);
        $chatWidget->register();

        /** @var LogRetentionScheduler $logRetentionScheduler */
        $logRetentionScheduler = $this->container->get(LogRetentionScheduler::class);
        $logRetentionScheduler->register();

        /** @var AnalyticsPage $analyticsPage */
        $analyticsPage = $this->container->get(AnalyticsPage::class);
        $analyticsPage->register();
    }

    /**
     * Runs on activation: provisions the custom capability, creates the
     * indexing database tables, and schedules the daily re-index and
     * five-minute embedding safety-net cron jobs. Kept side-effect-light
     * and idempotent so re-activating never corrupts existing data.
     */
    public static function activate(): void
    {
        Capabilities::register();
        Capabilities::grantToAdministrators();
        Schema::install();
        ReindexScheduler::scheduleDailySync();
        EmbeddingQueueScheduler::scheduleRecurring();
        LogRetentionScheduler::scheduleRecurring();
    }

    public static function deactivate(): void
    {
        ReindexScheduler::unscheduleDailySync();
        EmbeddingQueueScheduler::unschedule();
        LogRetentionScheduler::unschedule();
    }
}
