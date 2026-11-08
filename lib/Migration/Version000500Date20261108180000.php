<?php

declare(strict_types=1);

namespace OCA\AutoSort\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000500Date20261108180000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('autosort_log');
        if ($table->hasColumn('file_id')) {
            return null;
        }
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => false]);

        return $schema;
    }
}
