<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Settings\SettingsManager;
use Chamilo\CourseBundle\Entity\CCalendarEvent;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Doctrine\ORM\EntityManagerInterface;
use GradebookUtils;
use LinkFactory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @implements ProcessorInterface<CHomeworkAssignment, CHomeworkAssignment>
 */
final class CHomeworkAssignmentPostStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly SettingsManager $settingsManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): CHomeworkAssignment {
        /** @var CHomeworkAssignment $assignment */
        $assignment = $data;
        $isUpdate = null !== $assignment->getIid();

        // Persist/flush (ApiPlatform default processor)
        $result = $this->persistProcessor->process($assignment, $operation, $uriVariables, $context);

        $courseLink = $assignment->getFirstResourceLink();
        $course = $courseLink->getCourse();
        $session = $courseLink->getSession();

        if ($assignment->isAddToCalendar()) {
            $event = $this->saveCalendarEvent($assignment, $courseLink, $course, $session);
            $assignment->setEventCalendarId($event->getIid());
        } else {
            // Covers unchecking "Add to calendar" on an assignment that
            // already had one: without this, the old CCalendarEvent was
            // never deleted/unlinked and kept showing in the agenda
            // (eventCalendarId also stayed pointing at it). A brand new
            // assignment has no existing event, so this is a no-op there.
            $existingEventId = $assignment->getEventCalendarId();
            if ($existingEventId > 0) {
                $existingEvent = $this->entityManager->getRepository(CCalendarEvent::class)->find($existingEventId);
                if ($existingEvent instanceof CCalendarEvent) {
                    $this->entityManager->remove($existingEvent);
                }
            }
            $assignment->setEventCalendarId(0);
        }

        $this->entityManager->flush();

        $this->saveGradebookConfig($assignment, $course, $session);

        if (!$isUpdate) {
            $this->sendEmailAlertStudentsOnNewHomework($assignment, $course, $session);
        }

        return $result;
    }

    private function saveCalendarEvent(
        CHomeworkAssignment $assignment,
        ResourceLink $courseLink,
        Course $course,
        ?Session $session,
    ): CCalendarEvent {
        $eventTitle = \sprintf(
            $this->translator->trans('Homework deadline: %s'),
            $assignment->getTitle()
        );

        // getResourceNode() is unguarded here (as ->getId() on null would return
        // null via the nullsafe operator, producing a malformed URL rather than
        // throwing) because this method only ever runs after
        // $this->persistProcessor->process() has already persisted $assignment -
        // ResourceListener::prePersist() guarantees a ResourceNode is attached
        // to every AbstractResource by that point. The same invariant does NOT
        // hold in homework.lib.php's sendEmailToStudentsOnHomeworkAssignmentCreation(),
        // which re-fetches the assignment independently and therefore guards
        // explicitly instead of relying on it.
        $assignmentUrl = api_get_path(WEB_PATH).'resources/homework/'.$assignment->getResourceNode()?->getId()
            .'?'.http_build_query(['cid' => $course->getId(), 'sid' => $session?->getId()]);

        $content = \sprintf(
            '<div><a href="%s">%s</a></div> %s',
            $assignmentUrl,
            $assignment->getTitle(),
            $assignment->getDescription()
        );

        // CHomeworkAssignment::deadline is NOT nullable (unlike CStudentPublicationAssignment::expiresOn
        // in the reference processor), so there is no "now" fallback needed here.
        $startDate = clone $assignment->getDeadline();
        $endDate = clone $assignment->getDeadline();

        // Default to the Work module's colour; the agenda_colors setting has no
        // dedicated 'homework' key today, so guard the array access instead of
        // indexing directly (a direct index would return null and setColor()
        // requires a non-nullable string, i.e. a TypeError).
        $color = CCalendarEvent::COLOR_STUDENT_PUBLICATION;
        if ($agendaColors = $this->settingsManager->getSetting('agenda.agenda_colors')) {
            $color = $agendaColors['homework'] ?? $color;
        }

        $creator = $assignment->getCreator();
        if ($creator instanceof User && null !== $creator->getId()) {
            $creator = $this->entityManager->getReference(User::class, $creator->getId());
        }

        // Reuse the assignment's existing calendar event (if any) instead of
        // creating a new one on every save - without this, each edit of an
        // assignment with "Add to calendar" enabled left the previous event(s)
        // behind, orphaned but still visible in the agenda.
        $event = null;
        $existingEventId = $assignment->getEventCalendarId();
        if ($existingEventId > 0) {
            $event = $this->entityManager->getRepository(CCalendarEvent::class)->find($existingEventId);
        }

        $isNewEvent = !$event instanceof CCalendarEvent;
        if ($isNewEvent) {
            $event = new CCalendarEvent();
        }

        // setTitle() must run before persist(): ResourceListener::prePersist()
        // reads getTitle() via getResourceName() during the prePersist
        // lifecycle event, and $title is a non-nullable typed property with no
        // default - persisting a CCalendarEvent before its title is set throws
        // "must not be accessed before initialization".
        $event
            ->setTitle($eventTitle)
            ->setContent($content)
            ->setStartDate($startDate)
            ->setEndDate($endDate)
            ->setColor($color)
        ;

        if ($isNewEvent) {
            $event
                ->setParent($course)
                ->setCreator($creator)
                ->addLink(clone $courseLink)
            ;
            $this->entityManager->persist($event);
        }

        $this->entityManager->flush();

        return $event;
    }

    private function saveGradebookConfig(CHomeworkAssignment $assignment, Course $course, ?Session $session): void
    {
        if ($assignment->getGradebookCategoryId() <= 0) {
            return;
        }

        // GradebookUtils::add_resource_to_course_gradebook() delegates to
        // LinkFactory::create($resource_type), whose switch statement only
        // recognizes a fixed set of LINK_* types (see
        // public/main/gradebook/lib/be/linkfactory.class.php). LINK_HOMEWORK
        // has no case there and no HomeworkLink (extends AbstractLink) class
        // exists yet - create() would return null and the very next line
        // inside add_resource_to_course_gradebook() would fatal-error calling
        // set_user_id() on it. Building that class is a separate, substantial
        // piece of work (see StudentPublicationLink, ~340 lines, needs
        // submission-scoring queries that depend on the not-yet-built
        // grading UI/repository methods), so until it exists we no-op instead
        // of crashing the request.
        if (null === LinkFactory::create(LINK_HOMEWORK)) {
            $this->logger->warning(
                'CHomeworkAssignmentPostStateProcessor: skipped gradebook link because '
                .'LinkFactory has no case for LINK_HOMEWORK yet (no HomeworkLink class registered).',
                ['assignmentId' => $assignment->getIid(), 'courseId' => $course->getId()]
            );

            return;
        }

        $gradebookLinkInfo = GradebookUtils::isResourceInCourseGradebook(
            $course->getId(),
            LINK_HOMEWORK,
            $assignment->getIid(),
            $session?->getId()
        );

        $linkId = empty($gradebookLinkInfo) ? null : $gradebookLinkInfo['id'];

        if ($assignment->isAddToGradebook()) {
            if (empty($linkId)) {
                // add_resource_to_course_gradebook() declares $resource_type as
                // string and $weight as ?int; under strict_types (this file)
                // passing the raw int LINK_HOMEWORK constant or the raw float
                // CHomeworkAssignment::getWeight() would both throw a TypeError
                // (verified: this is a pre-existing latent bug in the reference
                // CStudentPublicationPostStateProcessor, which passes both
                // unconverted). Cast explicitly instead of reproducing it.
                GradebookUtils::add_resource_to_course_gradebook(
                    $assignment->getGradebookCategoryId(),
                    $course->getId(),
                    (string) LINK_HOMEWORK,
                    $assignment->getIid(),
                    $assignment->getTitle(),
                    (int) $assignment->getWeight(),
                    0,
                    $assignment->getDescription(),
                    1,
                    $session?->getId()
                );
            } else {
                GradebookUtils::updateResourceFromCourseGradebook(
                    $linkId,
                    $course->getId(),
                    $assignment->getWeight()
                );
            }
        } else {
            // Delete everything of the gradebook for this $linkId
            GradebookUtils::remove_resource_from_course_gradebook($linkId);
        }
    }

    private function sendEmailAlertStudentsOnNewHomework(
        CHomeworkAssignment $assignment,
        Course $course,
        ?Session $session
    ): void {
        // NOTE for future maintainers:
        // - 'email_alert_students_on_new_homework' is the SAME course setting
        //   key used by the legacy Work tool (see work.lib.php and
        //   CStudentPublicationPostStateProcessor). It is not Huiswerk-specific:
        //   one on/off switch currently controls email alerts for both modules.
        // - Course setting values (see CourseHelper::getDefaultCourseSettings()):
        //   1 = alert students, 2 = alert DRH only. The DRH alert (value 2,
        //   sendEmailToDrhOnHomeworkCreation() in the reference) is
        //   intentionally NOT implemented here - out of scope for this
        //   processor - so only value 1 does anything for Huiswerk today.
        //
        // c_course_setting.value is a `longtext` column, so
        // api_get_course_setting() always returns a string (or -1 as an int
        // sentinel when unset/not found) - never a genuine int 1. Comparing
        // with `1 !== api_get_course_setting(...)` is therefore ALWAYS true
        // (1 !== '1'), making this method permanently dead code. Cast to int
        // first; keep the strict `1 !==` (rather than the reference's loose
        // switch/== semantics) so it still correctly excludes value 2.
        // Pass $course explicitly rather than relying on api_get_course_setting()'s
        // default fallback to api_get_course_info() (the ambient/session "current
        // course"). In production that fallback happens to resolve correctly
        // because CidReqListener seeds the session from the request's `cid` query
        // param before this processor runs, but it makes the lookup depend on
        // request-scoped global state instead of the Course object already in
        // scope here - passing it directly is both more robust and testable in
        // isolation (e.g. via reflection, outside a full HTTP request).
        if (1 !== (int) api_get_course_setting('email_alert_students_on_new_homework', $course)) {
            return;
        }

        sendEmailToStudentsOnHomeworkAssignmentCreation(
            $assignment->getIid(),
            $course->getId(),
            $session?->getId()
        );
    }
}
