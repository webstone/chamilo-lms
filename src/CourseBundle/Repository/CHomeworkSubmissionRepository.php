<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Repository;

use Chamilo\CoreBundle\Repository\ResourceRepository;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Doctrine\Persistence\ManagerRegistry;

final class CHomeworkSubmissionRepository extends ResourceRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CHomeworkSubmission::class);
    }

    /**
     * Used by CHomeworkAssignmentDeleteProcessor to block deleting an
     * assignment once a student has actually turned work in - drafts don't
     * count, only SUBMITTED/LATE do.
     */
    public function hasSubmittedSubmissions(CHomeworkAssignment $assignment): bool
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.iid)')
            ->andWhere('s.assignment = :assignment')
            ->andWhere('s.status IN (:submittedStatuses)')
            ->setParameter('assignment', $assignment)
            ->setParameter('submittedStatuses', [
                CHomeworkSubmission::STATUS_SUBMITTED,
                CHomeworkSubmission::STATUS_LATE,
            ])
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return $count > 0;
    }
}
