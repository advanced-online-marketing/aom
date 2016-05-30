<?php
/**
 * AOM - Piwik Advanced Online Marketing Plugin
 *
 * @author Daniel Stonies <daniel.stonies@googlemail.com>
 */
namespace Piwik\Plugins\AOM\Commands;

use Monolog\Logger;
use Piwik\Common;
use Piwik\Db;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Plugin\Manager;
use Piwik\Plugins\AOM\AOM;
use Piwik\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Piwik\Plugins\SitesManager\API as APISitesManager;

/**
 * Example:
 * ./console aom:reimport-visits --startDate=2016-01-06 --endDate=2016-01-06
 */
class ReimportVisits extends AbstactImportCommand
{
    protected function configure()
    {
        $this
            ->setName('aom:reimport-visits')
            ->addOption('startDate', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD')
            ->addOption('endDate', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD')
            ->setDescription(
                'Refills columns depending on entryUrl or referrer.'
            );
    }

    /**
     * Updates several visits with the given data.
     *
     * @param array $updateVisits A map with two entries: idvisit and an array for fields and values to be set
     * @throws \Exception
     */
    protected function updateVisits(array $updateVisits)
    {
        // TODO: Use only one statement
        foreach ($updateVisits as list($idvisit, $updates)) {
            $sql = 'UPDATE ' . Common::prefixTable('log_visit') . ' SET ';

            $firstUpdate = true;
            foreach ($updates as $key => $val) {
                if ($firstUpdate) {
                    $firstUpdate = false;
                } else {
                    $sql .= ', ';
                }
                $sql .= $key . ' = \'' . $val . '\'';
            }

            $sql .= ' WHERE idvisit = ' . $idvisit;

            DB::exec($sql);
        }
    }

    private function getParamsUrl($visit)
    {
        if (AOM::getPlatformFromUrl($visit['referer_url'])) {
            return $visit['referer_url'];
        }

        if (AOM::getPlatformFromUrl($visit['visit_entry_idaction_url'])) {
            return $visit['visit_entry_idaction_url'];
        }

        return null;
    }

    /**
     * Reimports a visit specific date.
     * This method must be public so that it can be called from Tasks.php.
     *
     * @param string $date YYYY-MM-DD
     * @throws \Exception
     */
    public function processDate($date)
    {
        // Get visits
        $visits = $this->getVisits($date);
        $totalPiwikVisits = count($visits);

        $updateStatements = [];

        foreach ($visits as $visit) {
            //Determine matching URL
            $url = $this->getParamsUrl($visit);
            if (!$url) {
                continue;
            }

            $updateMap = [];

            $platform = AOM::getPlatformFromUrl($url);
            if ($platform != $visit['aom_platform']) {
                $updateMap['aom_platform'] = $platform;
            }

            $adParams = AOM::getAdParamsFromUrl($url);

            $aomAdParams = json_encode($adParams);
            if ($aomAdParams != $visit['aom_ad_params']) {
                $updateMap['aom_ad_params'] = $aomAdParams;
            }

            $platform = AOM::getPlatformInstance($adParams['platform']);
            list($rowId, $adData) = $platform->getAdDataFromAdParams($visit['idsite'], $adParams);

            $aomAdData = json_encode($adData);
            if ($aomAdData != $visit['aom_ad_data']) {
                $updateMap['aom_ad_data'] = $aomAdData;
            }

            if ($rowId != $visit['aom_platform_row_id']) {
                $updateMap['aom_platform_row_id'] = $rowId;
            }

            if (count($updateMap)) {
                $updateStatements[] = [$visit['idvisit'], $updateMap];
            }
        }

        $this->updateVisits($updateStatements);
        $countUpdateStatements = count($updateStatements);

        $this->log(
            Logger::INFO,
            "{$date}: Found {$countUpdateStatements} out of  {$totalPiwikVisits} to reimport."
        );
    }


    /**
     * Returns the visits of all websites that occurred at the given date based on the website's timezone.
     *
     * @param string $date YYYY-MM-DD
     * @return array
     * @throws \Exception
     */
    private function getVisits($date)
    {
        // We assume that the website's timezone matches the timezone of all advertising platforms.
        $visits = [];

        // We get visits per website to consider the website's individual timezones.
        foreach (APISitesManager::getInstance()->getAllSites() as $site) {
            $visits = array_merge(
                $visits,
                Db::fetchAll(
                    'SELECT
                            v.idvisit AS idvisit,
                            v.idsite AS idsite,
                            v.referer_url as referer_url,
                            v.visit_entry_idaction_url as visit_entry_idaction_url,
                            v.aom_platform,
                            v.aom_ad_params,
                            v.aom_ad_data,
                            v.aom_platform_row_id,
                        FROM piwik_log_visit AS v LEFT JOIN piwik_log_conversion AS c ON v.idvisit = c.idvisit
                        WHERE v.idsite = ? AND v.visit_first_action_time >= ? AND v.visit_first_action_time <= ?',
                    [
                        $site['idsite'],
                        AOM::convertLocalDateTimeToUTC($date . ' 00:00:00', $site['timezone']),
                        AOM::convertLocalDateTimeToUTC($date . ' 23:59:59', $site['timezone']),
                    ]
                )
            );
        }


        $this->log(
            Logger::DEBUG,
            'Got ' . count($visits) . ' Piwik visits'
        );

        return $visits;
    }


}
