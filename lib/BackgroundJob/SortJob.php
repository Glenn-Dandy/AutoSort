<?php

declare(strict_types=1);

namespace OCA\AutoSort\BackgroundJob;

use OCA\AutoSort\Service\Sorter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class SortJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private Sorter $sorter,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void {
        $user = $this->userManager->get($argument['userId']);
        if ($user === null) {
            return;
        }

        $this->userSession->setVolatileActiveUser($user);
        try {
            $this->sorter->sort($argument['userId'], $argument['fileId']);
        } catch (Throwable $e) {
            $this->logger->error('AutoSort could not sort file ' . $argument['fileId'] . ': ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
