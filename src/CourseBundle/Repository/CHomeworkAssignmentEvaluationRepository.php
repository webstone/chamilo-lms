<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Repository;

use Chamilo\CoreBundle\Repository\ResourceRepository;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use Doctrine\Persistence\ManagerRegistry;

final class CHomeworkAssignmentEvaluationRepository extends ResourceRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CHomeworkAssignmentEvaluation::class);
    }
}
