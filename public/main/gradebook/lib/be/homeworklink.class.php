<?php

/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Framework\Container;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;

/**
 * Gradebook link to a Homework/Verslagenmodule assignment.
 *
 * calc_score() branches on CHomeworkAssignment::isAllowMultipleSubmissions():
 * a plain assignment's score comes straight from the student's single
 * CHomeworkSubmission; a multi-report assignment's score comes from its
 * dedicated CHomeworkAssignmentEvaluation (the "final evaluation" - per-report
 * scores are informational only, never fed to the gradebook - see the
 * 2026-09-11 multiple-reports design spec).
 */
class HomeworkLink extends AbstractLink
{
    /**
     * CHomeworkAssignment has no per-assignment "max points" field (unlike
     * CStudentPublication::getQualification(), which StudentPublicationLink
     * uses as its denominator) - score is a free-form float with no stored
     * ceiling. This assumes a 0-100 scale; if Verslagenmodule scores are
     * meant to be on a different scale (e.g. 0-20), update this constant.
     */
    private const ASSUMED_MAX_SCORE = 100;

    public function __construct()
    {
        parent::__construct();
        $this->set_type(LINK_HOMEWORK);
    }

    public function get_type_name(): string
    {
        return get_lang('Reports');
    }

    public function is_allowed_to_change_name(): bool
    {
        return false;
    }

    public function needs_name_and_description(): bool
    {
        return false;
    }

    public function needs_max(): bool
    {
        return false;
    }

    public function needs_results(): bool
    {
        return false;
    }

    public function is_valid_link(): bool
    {
        return null !== $this->getAssignment();
    }

    public function has_results()
    {
        $assignment = $this->getAssignment();
        if (null === $assignment) {
            return false;
        }

        $repositoryClass = $assignment->isAllowMultipleSubmissions()
            ? CHomeworkAssignmentEvaluation::class
            : CHomeworkSubmission::class;

        $count = Database::getManager()->getRepository($repositoryClass)
            ->createQueryBuilder('r')
            ->select('COUNT(r.iid)')
            ->andWhere('r.assignment = :assignment')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return $count > 0;
    }

    public function calc_score($studentId = null, $type = null)
    {
        $assignment = $this->getAssignment();
        if (null === $assignment) {
            return [];
        }

        return $assignment->isAllowMultipleSubmissions()
            ? $this->calcScoreFromEvaluations($assignment, $studentId, $type)
            : $this->calcScoreFromSubmission($assignment, $studentId, $type);
    }

    public function get_name(): string
    {
        $assignment = $this->getAssignment();

        return null === $assignment ? get_lang('Untitled') : $assignment->getTitle();
    }

    public function get_description(): string
    {
        $assignment = $this->getAssignment();

        return null === $assignment ? '' : (string) $assignment->getDescription();
    }

    public function get_link(): string
    {
        $assignment = $this->getAssignment();
        if (null === $assignment || null === $assignment->getResourceNode()) {
            return '';
        }

        $query = [
            'cid' => (int) $this->getCourseId(),
            'sid' => (int) $this->get_session_id(),
        ];

        return api_get_path(WEB_PATH).'resources/homework/'.$assignment->getResourceNode()->getId().'/?'.http_build_query($query);
    }

    public function get_icon_name(): string
    {
        return 'homework';
    }

    private function getAssignment(): ?CHomeworkAssignment
    {
        if (empty($this->get_ref_id())) {
            return null;
        }

        return Database::getManager()->getRepository(CHomeworkAssignment::class)->find($this->get_ref_id());
    }

    private function calcScoreFromSubmission(CHomeworkAssignment $assignment, $studentId, $type)
    {
        $studentId = (int) $studentId;
        $em = Database::getManager();

        if (!empty($studentId)) {
            $submission = $em->getRepository(CHomeworkSubmission::class)->findOneBy([
                'assignment' => $assignment,
                'user' => $studentId,
            ]);

            if (null === $submission || null === $submission->getScore()) {
                return [null, null];
            }

            return [$submission->getScore(), self::ASSUMED_MAX_SCORE, $submission->getEvaluatedAt()?->format('Y-m-d H:i:s'), 1];
        }

        $rows = $em->getRepository(CHomeworkSubmission::class)
            ->createQueryBuilder('s')
            ->select('s.score', 'IDENTITY(s.user) AS userId')
            ->andWhere('s.assignment = :assignment')
            ->andWhere('s.score IS NOT NULL')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getArrayResult()
        ;

        return $this->aggregateScores($rows, $type, $studentId);
    }

    private function calcScoreFromEvaluations(CHomeworkAssignment $assignment, $studentId, $type)
    {
        $studentId = (int) $studentId;
        $em = Database::getManager();

        if (!empty($studentId)) {
            $evaluation = $em->getRepository(CHomeworkAssignmentEvaluation::class)->findOneBy([
                'assignment' => $assignment,
                'user' => $studentId,
            ]);

            if (null === $evaluation || null === $evaluation->getScore()) {
                return [null, null];
            }

            // Mirrors CHomeworkAssignmentEvaluation's Get security expression
            // (isVisibleToStudent() + the EDIT bypass) - this gradebook path
            // reads the score directly via Doctrine, bypassing the API
            // Platform security expression entirely, so it must enforce the
            // same deadline gate itself or a student would see their final
            // score here before the API would ever show it to them.
            if (!$evaluation->isVisibleToStudent() && !Container::getAuthorizationChecker()->isGranted('EDIT', $evaluation)) {
                return [null, null];
            }

            return [$evaluation->getScore(), self::ASSUMED_MAX_SCORE, $evaluation->getEvaluatedAt()?->format('Y-m-d H:i:s'), 1];
        }

        $rows = $em->getRepository(CHomeworkAssignmentEvaluation::class)
            ->createQueryBuilder('e')
            ->select('e.score', 'IDENTITY(e.user) AS userId')
            ->andWhere('e.assignment = :assignment')
            ->andWhere('e.score IS NOT NULL')
            ->setParameter('assignment', $assignment)
            ->getQuery()
            ->getArrayResult()
        ;

        return $this->aggregateScores($rows, $type, $studentId);
    }

    private function aggregateScores(array $rows, ?string $type, ?int $studentId): array
    {
        if (empty($rows)) {
            return [null, null];
        }

        $students = [];
        foreach ($rows as $row) {
            $students[(int) $row['userId']] = (float) $row['score'];
        }

        switch ($type) {
            case 'best':
                return [max($students), self::ASSUMED_MAX_SCORE];
            case 'average':
                return [array_sum($students) / \count($students), self::ASSUMED_MAX_SCORE];
            case 'ranking':
                return AbstractLink::getCurrentUserRanking($studentId, $students);
            default:
                return [array_sum($students), \count($students)];
        }
    }
}
