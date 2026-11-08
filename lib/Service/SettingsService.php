<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use OCA\AutoSort\AppInfo\Application;
use OCP\IConfig;

class SettingsService {
    public function __construct(private IConfig $config) {
    }

    public function getSource(string $userId): string {
        return $this->config->getUserValue($userId, Application::APP_ID, 'source', '');
    }

    public function getUnmatched(string $userId): string {
        return $this->config->getUserValue($userId, Application::APP_ID, 'unmatched', '');
    }

    public function getTriggerTag(string $userId): string {
        return $this->config->getUserValue($userId, Application::APP_ID, 'trigger_tag', '');
    }

    public function getAfterTag(string $userId): string {
        return $this->config->getUserValue($userId, Application::APP_ID, 'after_tag', '');
    }

    public function getRemoveTrigger(string $userId): bool {
        return $this->config->getUserValue($userId, Application::APP_ID, 'remove_trigger', '1') === '1';
    }

    public function saveTags(string $userId, string $triggerTag, string $afterTag, bool $removeTrigger): void {
        $this->config->setUserValue($userId, Application::APP_ID, 'trigger_tag', $triggerTag);
        $this->config->setUserValue($userId, Application::APP_ID, 'after_tag', $afterTag);
        $this->config->setUserValue($userId, Application::APP_ID, 'remove_trigger', $removeTrigger ? '1' : '0');
    }

    public function save(string $userId, string $source, string $unmatched): void {
        $this->config->setUserValue($userId, Application::APP_ID, 'source', $source);
        $this->config->setUserValue($userId, Application::APP_ID, 'unmatched', $unmatched);
    }
}
