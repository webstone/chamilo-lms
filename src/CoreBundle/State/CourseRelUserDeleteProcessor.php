<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\TrackEDefault;
use Chamilo\CoreBundle\Entity\User;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Throwable;

/**
 * Safe subset of CourseManager::unsubscribe_user() for one student relation:
 * the native routine ignores the relation status and can detach the course
 * from whole sessions, so it is not called here.
 *
 * @implements ProcessorInterface<CourseRelUser, void>
 */
final class CourseRelUserDeleteProcessor implements ProcessorInterface
{
    // COURSE_RELATION_TYPE_RRHH in the legacy api.lib.php
    private const RELATION_TYPE_RRHH = 1;

    private const COURSE_LEFTOVER_TABLES = [
        'c_group_rel_user',
        'c_group_rel_tutor',
        'c_forum_notification',
        'c_forum_mailcue',
    ];

    public function __construct(
        private readonly ProcessorInterface $removeProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {}

    public function process($data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof CourseRelUser) {
            return;
        }

        if (CourseRelUser::STUDENT !== $data->getStatus() || self::RELATION_TYPE_RRHH === $data->getRelationType()) {
            throw new CourseRelUserNotStudentException();
        }

        $course = $data->getCourse();
        $user = $data->getUser();
        $courseId = (int) $course->getId();
        $userId = (int) $user->getId();
        $actor = $this->security->getUser();
        $actorId = $actor instanceof User ? (int) $actor->getId() : 0;
        $connection = $this->entityManager->getConnection();

        $connection->beginTransaction();

        try {
            // A remaining non-RRHH relation (e.g. the user is also the
            // course's teacher) owns these group/tutor/forum rows too, so
            // they must survive this one relation being removed.
            $hasRemainingRole = (bool) $connection->fetchOne(
                'SELECT COUNT(*) FROM course_rel_user WHERE c_id = ? AND user_id = ? AND id <> ? AND relation_type <> ?',
                [$courseId, $userId, (int) $data->getId(), self::RELATION_TYPE_RRHH]
            );

            if (!$hasRemainingRole) {
                foreach (self::COURSE_LEFTOVER_TABLES as $table) {
                    $connection->executeStatement(
                        "DELETE FROM {$table} WHERE c_id = ? AND user_id = ?",
                        [$courseId, $userId]
                    );
                }
            }

            // Mirrors the two rows Event::addEvent() writes for a User value
            // (public/main/inc/lib/events.lib.php), the native unsubscribe format.
            $this->entityManager->persist($this->buildTrackRow($actorId, $courseId, 'course_code', $course->getCode()));
            $this->entityManager->persist($this->buildTrackRow($actorId, $courseId, 'user_object', serialize([
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'firstname' => $user->getFirstname(),
                'lastname' => $user->getLastname(),
            ])));

            // Removes the relation and flushes the pending TrackEDefault rows.
            $this->removeProcessor->process($data, $operation, $uriVariables, $context);

            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    private function buildTrackRow(int $actorId, int $courseId, string $valueType, string $value): TrackEDefault
    {
        return (new TrackEDefault())
            ->setDefaultUserId($actorId)
            ->setDefaultEventType('user_unsubscribed')
            ->setDefaultValueType($valueType)
            ->setDefaultValue($value)
            ->setDefaultDate(new DateTime('now', new DateTimeZone('UTC')))
            ->setCId($courseId)
            ->setSessionId(0)
        ;
    }
}
