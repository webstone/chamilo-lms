<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkFormField;
use Chamilo\CourseBundle\Entity\CHomeworkFormPage;
use Chamilo\CourseBundle\Repository\CHomeworkFormFieldRepository;
use Chamilo\CourseBundle\Repository\CHomeworkFormPageRepository;
use Chamilo\CourseBundle\Repository\CHomeworkFormRepository;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

class CHomeworkFormPageRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testFormWithPageAndFieldCascadesOnPersist(): void
    {
        $em = $this->getEntityManager();
        $formRepo = self::getContainer()->get(CHomeworkFormRepository::class);
        $pageRepo = self::getContainer()->get(CHomeworkFormPageRepository::class);
        $fieldRepo = self::getContainer()->get(CHomeworkFormFieldRepository::class);

        $course = $this->createCourse('new');

        $form = (new CHomeworkForm())->setTitle('Lesverslag');
        $form->setParent($course);
        $form->addCourseLink($course);

        $page = (new CHomeworkFormPage())->setTitle('Sectie 1')->setSortOrder(0);
        $form->addPage($page);

        // Added out of sortOrder order on purpose, to prove the fields collection
        // is actually re-read via the `#[ORM\OrderBy(['sortOrder' => 'ASC'])]` mapping
        // rather than merely reflecting insertion order.
        $fieldB = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_SELECT)
            ->setLabel('B - Beoordeling')
            ->setRequired(false)
            ->setSortOrder(1)
            ->setOptions(['Ja', 'Nee'])
        ;
        $page->addField($fieldB);

        $fieldA = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_TEXTAREA)
            ->setLabel('A - Wat heb je geleerd?')
            ->setRequired(true)
            ->setSortOrder(0)
        ;
        $page->addField($fieldA);

        $em->persist($form);
        $em->flush();

        $formIid = $form->getIid();
        $pageIid = $page->getIid();
        $fieldAIid = $fieldA->getIid();
        $fieldBIid = $fieldB->getIid();

        $this->assertNotNull($pageIid);
        $this->assertNotNull($fieldAIid);
        $this->assertNotNull($fieldBIid);
        $this->assertCount(1, $form->getPages());
        $this->assertCount(2, $page->getFields());

        // Detach everything and reload strictly through the repositories, so the
        // assertions below prove a real DB round-trip (FKs, column values, and
        // ordering) rather than just asserting on the still-in-memory graph.
        $em->clear();

        $this->assertSame(1, $pageRepo->count([]));
        $this->assertSame(2, $fieldRepo->count([]));

        /** @var CHomeworkFormPage $reloadedPage */
        $reloadedPage = $pageRepo->find($pageIid);

        $this->assertNotNull($reloadedPage);
        $this->assertSame('Sectie 1', $reloadedPage->getTitle());
        $this->assertSame(0, $reloadedPage->getSortOrder());
        $this->assertSame($formIid, $reloadedPage->getForm()->getIid());

        $reloadedFields = $reloadedPage->getFields();
        $this->assertCount(2, $reloadedFields);

        $reloadedFieldA = $reloadedFields->first();
        $reloadedFieldB = $reloadedFields->last();

        // sortOrder = 0 must come first even though it was persisted second.
        $this->assertSame($fieldAIid, $reloadedFieldA->getIid());
        $this->assertSame('A - Wat heb je geleerd?', $reloadedFieldA->getLabel());
        $this->assertSame(CHomeworkFormField::TYPE_TEXTAREA, $reloadedFieldA->getType());
        $this->assertTrue($reloadedFieldA->isRequired());
        $this->assertNull($reloadedFieldA->getOptions());

        $this->assertSame($fieldBIid, $reloadedFieldB->getIid());
        $this->assertSame('B - Beoordeling', $reloadedFieldB->getLabel());
        $this->assertSame(CHomeworkFormField::TYPE_SELECT, $reloadedFieldB->getType());
        $this->assertFalse($reloadedFieldB->isRequired());
        $this->assertSame(['Ja', 'Nee'], $reloadedFieldB->getOptions());

        // Removing the parent form must cascade all the way down to the page and
        // its fields (mapped via cascade: ['persist', 'remove'] + orphanRemoval,
        // backed by ON DELETE CASCADE at the DB level).
        $reloadedForm = $formRepo->find($formIid);
        $em->remove($reloadedForm);
        $em->flush();

        $this->assertSame(0, $formRepo->count([]));
        $this->assertSame(0, $pageRepo->count([]));
        $this->assertSame(0, $fieldRepo->count([]));
    }
}
