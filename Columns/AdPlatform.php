<?php
/**
 * AOM - Piwik Advanced Online Marketing Plugin
 *
 * @author Daniel Stonies <daniel.stonies@googlemail.com>
 */
namespace Piwik\Plugins\AOM\Columns;

use Piwik\Db;
use Piwik\Plugin\Dimension\VisitDimension;


class AdPlatform extends VisitDimension
{
    protected $columnName = 'aom_platform';
    protected $columnType = 'VARCHAR(255) NULL';

    /**
     * The installation is already implemented based on the $columnName and $columnType.
     * We overwrite this method to add an index on the new column too.
     *
     * @return array
     */
    public function install()
    {
        $changes = parent::install();

        $changes['log_visit'][] = 'ADD INDEX index_aom_platform (aom_platform)';

        return $changes;
    }
}
