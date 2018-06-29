<?php

/**
 * @author  Russell Michell 2018 <russ@theruss.com>
 * @package silverstripe-verifiable
 */

namespace PhpTek\Verifiable\Cron;

use SilverStripe\CronTask\Interfaces\CronTask;
use PhpTek\Verifiable\ORM\FieldType\ChainpointProof;
use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Extensible;
use PhpTek\Verifiable\Verify\VerifiableExtension;
use SilverStripe\ORM\DataList;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * Assumes of course that the CronTask cron is running on the server. See README.
 * @todo Switch over to implementing CronTask...
 */
class ChainpointFullProofTask extends BuildTask
{
    use Extensible;

    /**
     * {@inheritdoc}
     */
    public function getSchedule()
    {
        return '0 1 * * *'; // 01:00 every day
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function run($request = null)
    {
        if (!$backend = $this->verifiableService->getBackend()->name() === 'chainpoint') {
            throw new \Exception(sprintf('Cannot use %s backend with %s!', $backend, __CLASS__));
        }

        // Get all records with partial proofs. Attempt to fetch their full proofs
        // from Tierion, then write them back to local DB
        $partials = $this->getPartials();
        $this->writeFull($partials);
    }

    /**
     * Fetch all partial proofs, ready to make them whole again.
     *
     * @return array
     * @todo Create a new version when updating the proof / update latest version
     */
    protected function getPartials()
    {
        // Get decorated classes
        $dataObjectSublasses = ClassInfo::getValidSubClasses(DataObject::class);
        $candidates = [];

        foreach ($dataObjectSublasses as $class) {
            $obj = Injector::inst()->create($class);

            if (!$obj->hasExtension(VerifiableExtension::class)) {
                continue;
            }

            // TODO chunk this
            $list = DataObject::get($class);

            foreach ($list as $item) {
                // TODO do this for ALL versions
                $versions = Versioned::get_all_versions($class, $item->ID)->sort('Version ASC');

                foreach ($versions as $version) {
                    $proof = $version->dbObject('Proof');

                    if ($proof->isPartial()) {
                        $candidates[$class][$version->RecordID][$version->Version] = $proof->getHashIdNode();
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Takes a large JSON string, converts it to a ChainpointProof object and
     * updates the necessary records.
     *
     * @param  array $partials
     * @return void
     */
    protected function writeFull(array $partials)
    {
        $writes = [];

        foreach ($partials as $class => $data) {
            foreach ($data as $recordId => $version) {
                $writes[$class][$recordId][$version] = $this->verifiableService->call('read', array_values($version));
            }
        }

        foreach ($writes as $class => $write) {
            foreach ($write as $recordId => $versionAndProof) {
                DataObject::get()->filter([
                    'ID' => $recordId,
                    'Version' => array_keys($versionAndProof)[0],
                ])
                        ->setValue('Proof', array_values($versionAndProof)[0])
                        ->write();
            }
        }
    }

}
