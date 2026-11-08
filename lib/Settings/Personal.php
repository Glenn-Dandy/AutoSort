<?php

declare(strict_types=1);

namespace OCA\AutoSort\Settings;

use OCA\AutoSort\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class Personal implements ISettings {
    public function getForm(): TemplateResponse {
        Util::addScript(Application::APP_ID, 'personal');
        Util::addStyle(Application::APP_ID, 'personal');
        return new TemplateResponse(Application::APP_ID, 'personal', [], '');
    }

    public function getSection(): string {
        return Application::APP_ID;
    }

    public function getPriority(): int {
        return 50;
    }
}
