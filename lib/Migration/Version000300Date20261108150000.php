<?php

declare(strict_types=1);

namespace OCA\AutoSort\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000300Date20261108150000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('autosort_rules');
        if ($table->hasColumn('category')) {
            return null;
        }
        $table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 100]);

        return $schema;
    }
}
