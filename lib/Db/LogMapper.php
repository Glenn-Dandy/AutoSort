<?php

declare(strict_types=1);

namespace OCA\AutoSort\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<LogEntry> */
class LogMapper extends QBMapper {
    private const KEEP = 200;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'autosort_log', LogEntry::class);
    }

    /** @return LogEntry[] */
    public function findLatest(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(self::KEEP);
        return $this->findEntities($qb);
    }

    public function clear(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    // Only the latest entries of each user are kept
    public function prune(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'DESC')
            ->setFirstResult(self::KEEP)
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $oldest = $result->fetchOne();
        $result->closeCursor();
        if ($oldest === false) {
            return;
        }

        $delete = $this->db->getQueryBuilder();
        $delete->delete($this->getTableName())
            ->where($delete->expr()->eq('user_id', $delete->createNamedParameter($userId)))
            ->andWhere($delete->expr()->lte('id', $delete->createNamedParameter((int)$oldest, IQueryBuilder::PARAM_INT)));
        $delete->executeStatement();
    }
}
