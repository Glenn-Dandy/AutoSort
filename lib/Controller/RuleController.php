<?php

declare(strict_types=1);

namespace OCA\AutoSort\Controller;

use OCA\AutoSort\AppInfo\Application;
use OCA\AutoSort\Db\LogMapper;
use OCA\AutoSort\Db\Rule;
use OCA\AutoSort\Db\RuleMapper;
use OCA\AutoSort\Service\PdfTextExtractor;
use OCA\AutoSort\Service\Sorter;
use OCA\AutoSort\Service\SettingsService;
use OCA\AutoSort\Service\TagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use RuntimeException;

class RuleController extends Controller {
    public function __construct(
        IRequest $request,
        private RuleMapper $ruleMapper,
        private LogMapper $logMapper,
        private SettingsService $settings,
        private TagService $tags,
        private Sorter $sorter,
        private PdfTextExtractor $extractor,
        private IRootFolder $rootFolder,
        private ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/state')]
    public function state(): DataResponse {
        return new DataResponse([
            'source' => $this->settings->getSource($this->userId),
            'unmatched' => $this->settings->getUnmatched($this->userId),
            'triggerTag' => $this->tags->nameOf($this->settings->getTriggerTag($this->userId)),
            'afterTag' => $this->tags->nameOf($this->settings->getAfterTag($this->userId)),
            'removeTrigger' => $this->settings->getRemoveTrigger($this->userId),
            'tags' => $this->tags->availableNames($this->userId),
            'rules' => $this->ruleMapper->findAllForUser($this->userId),
            'pdftotext' => $this->extractor->isAvailable(),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/settings')]
    public function saveSettings(string $source = '', string $unmatched = '', string $triggerTag = '', string $afterTag = '', bool $removeTrigger = true): DataResponse {
        $source = $this->cleanPath($source);
        $unmatched = $this->cleanPath($unmatched);
        if ($source === null || $unmatched === null) {
            return $this->invalid('Folder paths must not contain ".."');
        }
        if ($source !== '' && $source === $unmatched) {
            return $this->invalid('The folder for unsorted files has to differ from the inbox');
        }

        $triggerTag = trim($triggerTag);
        $afterTag = trim($afterTag);
        $triggerId = $triggerTag === '' ? '' : $this->tags->findId($this->userId, $triggerTag);
        $afterId = $afterTag === '' ? '' : $this->tags->findId($this->userId, $afterTag);
        if ($triggerId === null || $afterId === null) {
            return $this->invalid('This tag does not exist or cannot be assigned. Please create it first.');
        }
        if ($triggerId !== '' && $triggerId === $afterId) {
            return $this->invalid('The follow-up tag has to differ from the trigger tag');
        }

        $this->settings->save($this->userId, $source, $unmatched);
        $this->settings->saveTags($this->userId, $triggerId, $afterId, $removeTrigger);
        return new DataResponse(['source' => $source, 'unmatched' => $unmatched, 'triggerTag' => $triggerTag, 'afterTag' => $afterTag, 'removeTrigger' => $removeTrigger]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/rules')]
    public function create(array $terms = [], array $termsAny = [], string $target = '', string $filename = '', string $dateAfter = '', string $category = '', string $subcategory = '', bool $enabled = true): DataResponse {
        $rule = new Rule();
        $rule->setUserId($this->userId);
        $rule->setPosition(count($this->ruleMapper->findAllForUser($this->userId)));
        $error = $this->apply($rule, $terms, $termsAny, $target, $filename, $dateAfter, $category, $subcategory, $enabled);
        return $error ?? new DataResponse($this->ruleMapper->insert($rule));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'PUT', url: '/rules/{id}')]
    public function update(int $id, array $terms = [], array $termsAny = [], string $target = '', string $filename = '', string $dateAfter = '', string $category = '', string $subcategory = '', bool $enabled = true): DataResponse {
        try {
            $rule = $this->ruleMapper->findForUser($id, $this->userId);
        } catch (DoesNotExistException) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
        $error = $this->apply($rule, $terms, $termsAny, $target, $filename, $dateAfter, $category, $subcategory, $enabled);
        return $error ?? new DataResponse($this->ruleMapper->update($rule));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/rules/{id}')]
    public function delete(int $id): DataResponse {
        try {
            $this->ruleMapper->delete($this->ruleMapper->findForUser($id, $this->userId));
        } catch (DoesNotExistException) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/order')]
    public function order(array $ids = []): DataResponse {
        $position = 0;
        foreach ($ids as $id) {
            try {
                $rule = $this->ruleMapper->findForUser((int)$id, $this->userId);
            } catch (DoesNotExistException) {
                continue;
            }
            $rule->setPosition($position++);
            $this->ruleMapper->update($rule);
        }
        return new DataResponse([]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/category')]
    public function renameCategory(string $old = '', string $new = ''): DataResponse {
        return $this->renameInRules(
            $new,
            static fn (Rule $rule) => ($rule->getCategory() ?? '') === $old,
            static fn (Rule $rule, string $name) => $rule->setCategory($name),
        );
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/subcategory')]
    public function renameSubcategory(string $category = '', string $old = '', string $new = ''): DataResponse {
        return $this->renameInRules(
            $new,
            static fn (Rule $rule) => ($rule->getCategory() ?? '') === $category && ($rule->getSubcategory() ?? '') === $old,
            static fn (Rule $rule, string $name) => $rule->setSubcategory($name),
        );
    }

    /** Shows what would happen to a PDF without changing anything */
    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/test')]
    public function test(string $path = ''): DataResponse {
        try {
            $file = $this->rootFolder->getUserFolder($this->userId)->get($path);
        } catch (NotFoundException) {
            return new DataResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
        }
        if (!($file instanceof File) || $file->getMimetype() !== 'application/pdf') {
            return $this->invalid('Please choose a PDF file');
        }

        try {
            $result = $this->sorter->evaluate($this->userId, $file);
            $result['waitingForTag'] = $this->sorter->waitsForTag($this->userId, $file) ? $this->tags->nameOf($this->settings->getTriggerTag($this->userId)) : null;
            return new DataResponse($result);
        } catch (RuntimeException $e) {
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/sort')]
    public function sort(): DataResponse {
        try {
            return new DataResponse($this->sorter->sortInbox($this->userId));
        } catch (RuntimeException $e) {
            return $this->invalid($e->getMessage());
        }
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/log')]
    public function log(): DataResponse {
        return new DataResponse($this->logMapper->findLatest($this->userId));
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'DELETE', url: '/log')]
    public function clearLog(): DataResponse {
        $this->logMapper->clear($this->userId);
        return new DataResponse([]);
    }

    private function renameInRules(string $new, callable $matches, callable $rename): DataResponse {
        $new = trim($new);
        if ($new === '' || mb_strlen($new) > 100) {
            return $this->invalid('Please enter a category name of up to 100 characters');
        }
        foreach ($this->ruleMapper->findAllForUser($this->userId) as $rule) {
            if ($matches($rule)) {
                $rename($rule, $new);
                $this->ruleMapper->update($rule);
            }
        }
        return new DataResponse(['name' => $new]);
    }

    private function apply(Rule $rule, array $terms, array $termsAny, string $target, string $filename, string $dateAfter, string $category, string $subcategory, bool $enabled): ?DataResponse {
        $clean = static fn (array $list): array => array_values(array_filter(array_map(static fn ($term) => trim((string)$term), $list), static fn (string $term) => $term !== ''));
        $terms = $clean($terms);
        $termsAny = $clean($termsAny);
        $target = $this->cleanPath($target);
        $filename = trim($filename);
        $category = trim($category);
        $subcategory = trim($subcategory);

        if ($terms === [] && $termsAny === []) {
            return $this->invalid('A rule needs at least one word to look for');
        }
        if ($target === null || $target === '') {
            return $this->invalid('A rule needs a valid target folder');
        }
        if (mb_strlen($filename) > 200 || mb_strlen($target) > 1000 || mb_strlen($dateAfter) > 200 || mb_strlen($category) > 100 || mb_strlen($subcategory) > 100) {
            return $this->invalid('An entry is too long');
        }

        $rule->setTerms(json_encode($terms, JSON_UNESCAPED_UNICODE));
        $rule->setTermsAny(json_encode($termsAny, JSON_UNESCAPED_UNICODE));
        $rule->setTarget($target);
        $rule->setFilename($filename);
        $rule->setDateAfter(trim($dateAfter));
        $rule->setCategory($category);
        $rule->setSubcategory($subcategory);
        $rule->setEnabled($enabled);
        return null;
    }

    // Returns the path without surrounding slashes or null if it tries to leave the user's files
    private function cleanPath(string $path): ?string {
        $path = trim(trim($path), '/');
        return in_array('..', explode('/', $path), true) ? null : $path;
    }

    private function invalid(string $message): DataResponse {
        return new DataResponse(['message' => $message], Http::STATUS_BAD_REQUEST);
    }
}
