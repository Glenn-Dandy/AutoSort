<?php

declare(strict_types=1);

namespace OCA\AutoSort\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20261108120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('autosort_log')) {
            return null;
        }

        $table = $schema->createTable('autosort_log');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('created', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('file_name', Types::STRING, ['notnull' => true, 'length' => 255]);
        $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32]);
        $table->addColumn('destination', Types::STRING, ['notnull' => false, 'length' => 1000]);
        $table->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 1000]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'id'], 'autosort_log_user');

        return $schema;
    }
}
