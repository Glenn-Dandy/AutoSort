<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use OCA\AutoSort\Db\LogEntry;
use OCA\AutoSort\Db\LogMapper;
use OCA\AutoSort\Db\Rule;
use OCA\AutoSort\Db\RuleMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class Sorter {
    public const STATUS_NO_TEXT = 'no_text';
    public const STATUS_MATCH = 'match';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_NONE = 'none';
    public const STATUS_ERROR = 'error';
    public const STATUS_NO_TAG = 'no_tag';

    public function __construct(
        private IRootFolder $rootFolder,
        private RuleMapper $ruleMapper,
        private SettingsService $settings,
        private PdfTextExtractor $extractor,
        private TagService $tags,
        private LogMapper $logMapper,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /** Works out what would happen to a file without changing anything */
    public function evaluate(string $userId, File $file): array {
        $text = $this->extractor->extract($file);
        if (trim($text) === '') {
            return ['status' => self::STATUS_NO_TEXT, 'destination' => null, 'rules' => []];
        }

        $destination = null;
        $results = [];
        foreach ($this->ruleMapper->findAllForUser($userId) as $rule) {
            if (!$rule->getEnabled()) {
                continue;
            }
            $result = $this->evaluateRule($rule, $text, $file->getName());
            $results[] = $result;
            $destination ??= $result['destination'];
        }

        if ($destination !== null) {
            return ['status' => self::STATUS_MATCH, 'destination' => $destination, 'rules' => $results];
        }

        $unmatched = $this->settings->getUnmatched($userId);
        if ($unmatched !== '') {
            return ['status' => self::STATUS_UNMATCHED, 'destination' => $unmatched . '/' . $file->getName(), 'rules' => $results];
        }
        return ['status' => self::STATUS_NONE, 'destination' => null, 'rules' => $results];
    }

    private function evaluateRule(Rule $rule, string $text, string $originalName): array {
        $terms = [];
        $allFound = true;
        foreach ($rule->getTermList() as $term) {
            $found = RuleMatcher::containsTerm($text, $term);
            $terms[] = ['term' => $term, 'found' => $found];
            $allFound = $allFound && $found;
        }

        $termsAny = [];
        $anyFound = $rule->getAnyTermList() === [];
        // The first term of the OR list that occurs names the document
        $hit = $rule->getTermList()[0] ?? '';
        $hitFound = false;
        foreach ($rule->getAnyTermList() as $term) {
            $found = RuleMatcher::containsTerm($text, $term);
            $termsAny[] = ['term' => $term, 'found' => $found];
            $anyFound = $anyFound || $found;
            if ($found && !$hitFound) {
                $hit = $term;
                $hitFound = true;
            }
        }

        $date = RuleMatcher::findDate($text, $rule->getDateAfter() ?? '');
        $filename = $rule->getFilename() ?? '';
        // Without the date a wrong name would be built, so such a rule does not apply
        $dateMissing = $date === null && (RuleMatcher::usesDate($filename) || RuleMatcher::usesDate($rule->getTarget()));

        $destination = null;
        if ($allFound && $anyFound && !$dateMissing) {
            $folder = trim(RuleMatcher::fill($rule->getTarget(), $date, '', $hit), '/');
            $destination = $folder . '/' . RuleMatcher::buildFilename($filename, $date, $originalName, $hit);
        }

        return [
            'id' => $rule->getId(),
            'terms' => $terms,
            'termsAny' => $termsAny,
            'date' => $date?->format('Y.m.d'),
            'dateMissing' => $dateMissing,
            'destination' => $destination,
        ];
    }

    public function isInSource(string $userId, File $file): bool {
        $source = $this->settings->getSource($userId);
        if ($source === '') {
            return false;
        }
        return dirname($file->getPath()) === '/' . $userId . '/files/' . $source;
    }

    public function sort(string $userId, int $fileId): void {
        $userFolder = $this->rootFolder->getUserFolder($userId);
        $file = $userFolder->getFirstNodeById($fileId);
        if ($file instanceof File && $this->isInSource($userId, $file) && !$this->waitsForTag($userId, $file)) {
            $this->process($userId, $userFolder, $file);
        }
    }

    /**
     * Sorts every PDF that currently lies in the inbox
     *
     * @throws RuntimeException if no usable inbox folder is configured
     */
    public function sortInbox(string $userId): array {
        $source = $this->settings->getSource($userId);
        if ($source === '') {
            throw new RuntimeException('No inbox folder has been saved yet');
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $inbox = $userFolder->nodeExists($source) ? $userFolder->get($source) : null;
        if (!($inbox instanceof Folder)) {
            throw new RuntimeException('The inbox folder does not exist');
        }

        $results = [];
        foreach ($inbox->getDirectoryListing() as $node) {
            if (!($node instanceof File) || $node->getMimetype() !== 'application/pdf') {
                continue;
            }
            if ($this->waitsForTag($userId, $node)) {
                $this->log($userId, $node->getId(), $node->getName(), self::STATUS_NO_TAG, null, null);
                $results[] = ['fileName' => $node->getName(), 'status' => self::STATUS_NO_TAG, 'destination' => null, 'message' => null];
                continue;
            }
            $results[] = $this->process($userId, $userFolder, $node);
        }
        return $results;
    }

    // Applies the rules to one file and records the outcome in the user's log
    private function process(string $userId, Folder $userFolder, File $file): array {
        $name = $file->getName();
        try {
            $result = $this->evaluate($userId, $file);
            $status = $result['status'];
            $destination = $result['destination'] === null ? null : $this->move($userFolder, $file, $result['destination']);
            $message = null;
            if ($destination !== null) {
                $this->updateTags($userId, $file);
            }
        } catch (Throwable $e) {
            $this->logger->error('AutoSort could not sort ' . $name . ': ' . $e->getMessage(), ['exception' => $e]);
            $status = self::STATUS_ERROR;
            $destination = null;
            $message = mb_substr($e->getMessage(), 0, 1000);
        }

        $this->log($userId, $file->getId(), $name, $status, $destination, $message);
        return ['fileName' => $name, 'status' => $status, 'destination' => $destination, 'message' => $message];
    }

    public function waitsForTag(string $userId, File $file): bool {
        $tagId = $this->settings->getTriggerTag($userId);
        return $tagId !== '' && !$this->tags->hasTag($file->getId(), $tagId);
    }

    // A file that was moved is done, so the trigger tag can go and the follow-up tag can be set
    private function updateTags(string $userId, File $file): void {
        $triggerTag = $this->settings->getTriggerTag($userId);
        if ($triggerTag !== '' && $this->settings->getRemoveTrigger($userId)) {
            $this->tags->remove($file->getId(), $triggerTag);
        }
        $afterTag = $this->settings->getAfterTag($userId);
        if ($afterTag !== '') {
            $this->tags->add($file->getId(), $afterTag);
        }
    }

    private function log(string $userId, int $fileId, string $name, string $status, ?string $destination, ?string $message): void {
        $entry = new LogEntry();
        $entry->setUserId($userId);
        $entry->setCreated($this->timeFactory->getTime());
        $entry->setFileId($fileId);
        $entry->setFileName(mb_substr($name, 0, 255));
        $entry->setStatus($status);
        $entry->setDestination($destination);
        $entry->setMessage($message);
        $this->logMapper->insert($entry);
        $this->logMapper->prune($userId);
    }

    // Returns the path the file ended up at or null if it already was there
    private function move(Folder $userFolder, File $file, string $destination): ?string {
        $target = $this->ensureFolder($userFolder, dirname($destination));
        $name = basename($destination);
        if ($target->getPath() . '/' . $name === $file->getPath()) {
            return null;
        }
        $name = $this->freeName($target, $name);
        $file->move($target->getPath() . '/' . $name);
        return ltrim($userFolder->getRelativePath($target->getPath()) . '/' . $name, '/');
    }

    // Keeps the name if it is free, otherwise numbers it: name_1.pdf, name_2.pdf, ...
    private function freeName(Folder $folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $extension = $dot === false ? '' : substr($name, $dot);

        $number = 1;
        while ($folder->nodeExists($base . '_' . $number . $extension)) {
            $number++;
        }
        return $base . '_' . $number . $extension;
    }

    private function ensureFolder(Folder $folder, string $path): Folder {
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $folder = $folder->nodeExists($part) ? $folder->get($part) : $folder->newFolder($part);
            if (!($folder instanceof Folder)) {
                throw new RuntimeException('"' . $part . '" in the target path is not a folder');
            }
        }
        return $folder;
    }
}
