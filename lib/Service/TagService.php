<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use OCP\IUserManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;

class TagService {
    private const OBJECT_TYPE = 'files';

    public function __construct(
        private ISystemTagManager $tagManager,
        private ISystemTagObjectMapper $objectMapper,
        private IUserManager $userManager,
    ) {
    }

    /** @return ISystemTag[] the tags this user may assign */
    private function assignableTags(string $userId): array {
        $user = $this->userManager->get($userId);
        return array_filter($this->tagManager->getAllTags(true), fn (ISystemTag $tag) => $this->tagManager->canUserAssignTag($tag, $user));
    }

    /** @return string[] */
    public function availableNames(string $userId): array {
        $names = array_map(static fn (ISystemTag $tag) => $tag->getName(), $this->assignableTags($userId));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($names);
    }

    public function findId(string $userId, string $name): ?string {
        foreach ($this->assignableTags($userId) as $tag) {
            if ($tag->getName() === $name) {
                return $tag->getId();
            }
        }
        return null;
    }

    public function nameOf(string $id): string {
        if ($id === '') {
            return '';
        }
        try {
            $tags = $this->tagManager->getTagsByIds([$id]);
        } catch (TagNotFoundException) {
            return '';
        }
        return $tags === [] ? '' : array_values($tags)[0]->getName();
    }

    public function hasTag(int $fileId, string $tagId): bool {
        try {
            return $this->objectMapper->haveTag((string)$fileId, self::OBJECT_TYPE, $tagId);
        } catch (TagNotFoundException) {
            return false;
        }
    }

    public function add(int $fileId, string $tagId): void {
        $this->objectMapper->assignTags((string)$fileId, self::OBJECT_TYPE, $tagId);
    }

    public function remove(int $fileId, string $tagId): void {
        $this->objectMapper->unassignTags((string)$fileId, self::OBJECT_TYPE, $tagId);
    }
}
