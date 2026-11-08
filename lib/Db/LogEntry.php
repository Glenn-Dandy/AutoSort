<?php

declare(strict_types=1);

namespace OCA\AutoSort\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method void setUserId(string $userId)
 * @method void setCreated(int $created)
 * @method void setFileId(?int $fileId)
 * @method void setFileName(string $fileName)
 * @method void setStatus(string $status)
 * @method void setDestination(?string $destination)
 * @method void setMessage(?string $message)
 */
class LogEntry extends Entity implements JsonSerializable {
    protected $userId = '';
    protected $created = 0;
    protected $fileId;
    protected $fileName = '';
    protected $status = '';
    protected $destination;
    protected $message;

    public function __construct() {
        $this->addType('created', Types::BIGINT);
        $this->addType('fileId', Types::BIGINT);
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'created' => $this->created,
            'fileId' => $this->fileId,
            'fileName' => $this->fileName,
            'status' => $this->status,
            'destination' => $this->destination,
            'message' => $this->message,
        ];
    }
}
