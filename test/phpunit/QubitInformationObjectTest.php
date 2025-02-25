<?php

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \QubitInformationObject::getByTitleIdentifierAndRepo
 */
class QubitInformationObjectTest extends TestCase
{
    protected $io;
    protected $repo;

    /**
     * @dataProvider dataProviderForGetByTitleIdentifierAndRepo
     *
     * @param string      $identifier    the information object identifier
     * @param string      $title         the information object title
     * @param null|string $repoName      the repository authorized_form_of_name
     * @param null|string $expectedTitle expected localized title if a match is found; otherwise, null
     * @param mixed       $hasLinkedRepo
     */
    public function testGetByTitleIdentifierAndRepo($identifier, $title, $repoName, $hasLinkedRepo, $expectedTitle)
    {
        if (null !== $this->io) {
            $io = $this->io;
        } else {
            $io = new QubitInformationObject();
        }

        $io->title = 'TestDescriptionTitle';
        $io->identifier = 'TestDescriptionIdentifier';

        if (true === $hasLinkedRepo) {
            if (null !== $this->repo) {
                $repository = $this->repo;
            } else {
                $repository = new QubitRepository();
            }
            $repository = new QubitRepository();
            $repository->indexOnSave = false;
            $repository->setAuthorizedFormOfName('TestRepository');
            $repository->save();
            $io->setRepositoryId($repository->id);
            $this->repo = $repository;
        }

        $io->indexOnSave = false;
        $io->save();
        $this->io = $io;

        $result = QubitInformationObject::getByTitleIdentifierAndRepo($identifier, $title, $repoName);

        if (null === $expectedTitle) {
            // No match expected.
            $this->assertNull($result, 'Expected null when no matching record exists.');
        } else {
            // A match is expected.
            $this->assertNotNull($result, 'Expected a valid integer id when data should match.');
            $this->assertIsInt($result, 'Expected the returned id to be an integer.');
        }
    }

    public function dataProviderForGetByTitleIdentifierAndRepo()
    {
        // Order of fields: $identifier, $title, $repoName, $hasLinkedRepo, $expectedTitle
        return [
            // Id, title and repository specified but repo not linked: matching fail.
            ['TestDescriptionIdentifier', 'TestDescriptionTitle', 'TestRepository', false, null],
            // Id, title specified only and repo is linked: matching success.
            ['TestDescriptionIdentifier', 'TestDescriptionTitle', null, false, 'exampleTitle1'],
            // Id, title and repository specified but title not matched and repo missing: matching fail.
            ['TestDescriptionIdentifier', 'TestDescriptionTitleX', 'TestRepository', false, null],
            // Id, title and repository specified but id not matched and repo missing: matching fail.
            ['TestDescriptionIdentifierX', 'TestDescriptionTitle', 'TestRepository', false, null],
            // Id, title and repository specified in lookup & all exist: matched.
            ['TestDescriptionIdentifier', 'TestDescriptionTitle', 'TestRepository', true, 'exampleTitle1'],
            // Id, title specified only & repo not linked: matching success.
            ['TestDescriptionIdentifier', 'TestDescriptionTitle', null, true, 'exampleTitle1'],
            // Id, title and repository specified but repo not matched: matching fail.
            ['TestDescriptionIdentifier', 'TestDescriptionTitle', 'TestRepositoryX', true, null],
            // Id, title and repository specified but title not matched: matching fail.
            ['TestDescriptionIdentifier', 'TestDescriptionTitleX', 'TestRepository', true, null],
            // Id, title and repository specified but id not matched: matching fail.
            ['TestDescriptionIdentifierX', 'TestDescriptionTitle', 'TestRepository', true, null],
        ];
    }
}
