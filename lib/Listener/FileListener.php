<?php

declare(strict_types=1);

namespace OCA\AutoSort\Listener;

use OCA\AutoSort\BackgroundJob\SortJob;
use OCA\AutoSort\Service\Sorter;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

/** @implements IEventListener<Event> */
class FileListener implements IEventListener {
    public function __construct(private Sorter $sorter, private IJobList $jobList) {
    }

    public function handle(Event $event): void {
        if ($event instanceof NodeRenamedEvent) {
            $node = $event->getTarget();
        } elseif ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
            $node = $event->getNode();
        } else {
            return;
        }

        if (!($node instanceof File) || $node->getMimetype() !== 'application/pdf') {
            return;
        }

        // Paths look like /user/files/...
        $userId = explode('/', $node->getPath())[1] ?? '';
        if ($node->getOwner()?->getUID() !== $userId || !$this->sorter->isInSource($userId, $node)) {
            return;
        }

        // The text is read in the job so that uploads stay fast. IJobList ignores duplicates.
        $this->jobList->add(SortJob::class, ['userId' => $userId, 'fileId' => $node->getId()]);
    }
}
