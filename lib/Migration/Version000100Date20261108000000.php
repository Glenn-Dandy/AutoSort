<?php

declare(strict_types=1);

namespace OCA\AutoSort\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20261108000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('autosort_rules')) {
            return null;
        }

        $table = $schema->createTable('autosort_rules');
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->addColumn('terms', Types::TEXT, ['notnull' => true]);
        $table->addColumn('target', Types::STRING, ['notnull' => true, 'length' => 1000]);
        $table->addColumn('filename', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('date_after', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'position'], 'autosort_user_pos');

        return $schema;
    }
}
