<?php

declare(strict_types=1);

namespace OCA\AutoSort\AppInfo;

use OCA\AutoSort\Listener\FileListener;
use OCA\AutoSort\Listener\TagListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\SystemTag\MapperEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'autosort';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(NodeCreatedEvent::class, FileListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, FileListener::class);
        // Written also fires when an OCR app saves the recognized version of a PDF
        $context->registerEventListener(NodeWrittenEvent::class, FileListener::class);
        $context->registerEventListener(MapperEvent::EVENT_ASSIGN, TagListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
