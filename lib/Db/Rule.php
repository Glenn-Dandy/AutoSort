<?php

declare(strict_types=1);

namespace OCA\AutoSort\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getPosition()
 * @method void setPosition(int $position)
 * @method string getTerms()
 * @method void setTerms(string $terms)
 * @method string|null getTermsAny()
 * @method void setTermsAny(?string $termsAny)
 * @method string getTarget()
 * @method void setTarget(string $target)
 * @method string|null getFilename()
 * @method void setFilename(?string $filename)
 * @method string|null getDateAfter()
 * @method void setDateAfter(?string $dateAfter)
 * @method string|null getSubcategory()
 * @method void setSubcategory(?string $subcategory)
 * @method string|null getCategory()
 * @method void setCategory(?string $category)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 */
class Rule extends Entity implements JsonSerializable {
    protected $userId = '';
    protected $position = 0;
    protected $terms = '';
    protected $termsAny;
    protected $target = '';
    protected $filename;
    protected $dateAfter;
    protected $category;
    protected $subcategory;
    protected $enabled = true;

    public function __construct() {
        $this->addType('position', Types::INTEGER);
        $this->addType('enabled', Types::BOOLEAN);
    }

    /** @return string[] */
    public function getTermList(): array {
        return json_decode($this->terms, true) ?: [];
    }

    /** @return string[] */
    public function getAnyTermList(): array {
        return json_decode($this->termsAny ?? '[]', true) ?: [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'terms' => $this->getTermList(),
            'termsAny' => $this->getAnyTermList(),
            'target' => $this->target,
            'filename' => $this->filename ?? '',
            'dateAfter' => $this->dateAfter ?? '',
            'category' => $this->category ?? '',
            'subcategory' => $this->subcategory ?? '',
            'enabled' => (bool)$this->enabled,
        ];
    }
}
