<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Repository;

use Chamilo\CoreBundle\Traits\NonResourceRepository;
use Chamilo\CourseBundle\Entity\CHomeworkFormPage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CHomeworkFormPageRepository extends ServiceEntityRepository
{
    use NonResourceRepository;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CHomeworkFormPage::class);
    }
}
