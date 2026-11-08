<?php

declare(strict_types=1);

namespace OCA\AutoSort\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<Rule> */
class RuleMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'autosort_rules', Rule::class);
    }

    public function findForUser(int $id, string $userId): Rule {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    /** @return Rule[] */
    public function findAllForUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('position', 'ASC')
            ->addOrderBy('id', 'ASC');
        return $this->findEntities($qb);
    }
}
