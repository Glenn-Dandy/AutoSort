<?php

declare(strict_types=1);

namespace OCA\AutoSort\Listener;

use OCA\AutoSort\BackgroundJob\SortJob;
use OCA\AutoSort\Service\SettingsService;
use OCA\AutoSort\Service\Sorter;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\SystemTag\MapperEvent;

/** @implements IEventListener<Event> */
class TagListener implements IEventListener {
    public function __construct(
        private IRootFolder $rootFolder,
        private Sorter $sorter,
        private SettingsService $settings,
        private IJobList $jobList,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof MapperEvent) || $event->getObjectType() !== 'files') {
            return;
        }

        $node = $this->rootFolder->getFirstNodeById((int)$event->getObjectId());
        if (!($node instanceof File) || $node->getMimetype() !== 'application/pdf') {
            return;
        }

        // The tag can be assigned by anyone with access, the owner's settings decide
        $userId = $node->getOwner()?->getUID() ?? '';
        if ($userId === '' || !in_array($this->settings->getTriggerTag($userId), array_map('strval', $event->getTags()), true)) {
            return;
        }

        $file = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($node->getId());
        if ($file instanceof File && $this->sorter->isInSource($userId, $file)) {
            $this->jobList->add(SortJob::class, ['userId' => $userId, 'fileId' => $file->getId()]);
        }
    }
}
