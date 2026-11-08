<?php

declare(strict_types=1);

namespace OCA\AutoSort\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000600Date20261108200000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('autosort_rules');
        if ($table->hasColumn('terms_any')) {
            return null;
        }
        $table->addColumn('terms_any', Types::TEXT, ['notnull' => false]);

        return $schema;
    }
}
