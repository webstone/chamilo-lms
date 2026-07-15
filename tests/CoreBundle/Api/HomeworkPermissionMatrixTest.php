<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

/**
 * Functional (HTTP, full API-Platform pipeline) coverage for the permission
 * model described in docs/superpowers/specs/2026-07-14-huiswerk-module-design.md,
 * "Permissies & rechten": cursist-isolation, course-wide (cross-session)
 * teacher rights, and platform-admin override.
 *
 * Fixture patterns are the established ones from this branch:
 *   - Authenticated HTTP requests: CHomeworkAssignmentPostStateProcessorTest
 *     (Task 9), which is itself modeled on CToolIntroRepositoryTest.
 *   - Session-scoped teacher via Session::addUserInCourse(COURSE_COACH, ...):
 *     HomeworkCourseTeacherCheckerTest (Task 7).
 *   - Direct entity/ResourceNode fixture construction (setParent() +
 *     addCourseLink()/addUserLink()): CHomeworkAssignmentRepositoryTest and
 *     CHomeworkSubmissionRepositoryTest (Tasks 5/6).
 *
 * IMPORTANT fixture note - CHomeworkSubmission privacy:
 * Submissions in these tests are linked with addUserLink($student, $course, ...)
 * rather than addCourseLink($course, ...). This is deliberate, not
 * cosmetic: all test courses are Course::OPEN_PLATFORM (ChamiloTestTrait::
 * createCourse()), and for an OPEN_PLATFORM course CourseAccessResolver
 * grants ROLE_CURRENT_COURSE_STUDENT to ANY authenticated user regardless of
 * actual enrollment. Combined with ResourceNodeVoter's generic
 * "course-level PUBLISHED link + ROLE_CURRENT_COURSE_STUDENT" grant, a
 * course-level link (addCourseLink(), as used by the Task 5/6 CRUD-only
 * repository tests) would make ANY authenticated user able to view ANY
 * other student's submission - defeating the "cursist: enkel eigen
 * indieningen" requirement entirely. CHomeworkSubmission has no
 * ResourceNodeVoter special case comparable to the 'student_publications'
 * one (see ResourceNodeVoter::canViewOwnStudentPublicationRelatedResource()),
 * so a user-scoped ResourceLink (or the generic "I'm the resourceNode
 * creator" ownership check, which addUserLink()'s creator wiring also
 * satisfies here since we explicitly setCreator($student)) is the only
 * mechanism that actually enforces per-student privacy today. This is a
 * fixture-shape requirement for whatever eventually builds real submissions
 * (the CHomeworkSubmission Post path / future HomeworkSubmit.vue), not just
 * a test artifact.
 *
 * NOTE: as documented in CHomeworkAssignmentPostStateProcessorTest, this
 * environment has a known infra gap where anything using
 * ChamiloTestTrait::createCourse() fails with "Access denied ... chamilo_test"
 * under `phpunit`. These tests could not be confirmed green via phpunit here;
 * see the Task 10 report for the dev-DB verification harness used instead
 * (same precedent as Task 9).
 */
final class HomeworkPermissionMatrixTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testStudentCannotViewAnotherStudentsSubmission(): void
    {
        $studentA = $this->createUser('homework_matrix_student_a_isolation');
        $studentB = $this->createUser('homework_matrix_student_b_isolation');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture('isolation', $studentA);

        $token = $this->getUserTokenFromUser($studentB);

        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Collection-level counterpart to testStudentCannotViewAnotherStudentsSubmission():
     * the item-level Get is gated by ResourceNodeVoter's per-link check, but
     * GetCollection has NO row-level filter of its own - only the
     * CHomeworkSubmissionExtension QueryCollectionExtension added alongside
     * HomeworkSubmit.vue (Task 15) closes that gap. Without it, student B's
     * GET /api/c_homework_submissions would return BOTH submissions
     * (including student A's score/feedback/answers), even though the item
     * Get for student A's submission correctly 403s for student B.
     */
    public function testStudentCannotListAnotherStudentsSubmissionInCollection(): void
    {
        $studentA = $this->createUser('homework_matrix_student_a_collection');
        $studentB = $this->createUser('homework_matrix_student_b_collection');

        [$course, $assignment, $submissionA] = $this->buildSingleStudentSubmissionFixture('collection', $studentA);

        // Second submission to the SAME assignment, owned by student B - the
        // exact scenario a shared "list submissions for this assignment"
        // collection call (chomeworksubmission.js's listSubmissionsForAssignment(),
        // used by HomeworkSubmit.vue's find-or-create flow) would hit.
        $em = $this->getEntityManager();
        $submissionB = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($studentB)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setCreator($studentB)
        ;
        $submissionB->setParent($course);
        $submissionB->addUserLink($studentB, $course);
        $em->persist($submissionB);
        $em->flush();

        $token = $this->getUserTokenFromUser($studentA);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions',
            ['query' => ['cid' => $course->getId(), 'assignment.iid' => $assignment->getIid()]]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $members = $data['hydra:member'] ?? [];

        $this->assertCount(1, $members, "Collection must contain only the requesting student's own submission.");
        $this->assertSame(
            '/api/c_homework_submissions/'.$submissionA->getIid(),
            $members[0]['@id'] ?? null
        );
    }

    public function testStudentCanViewOwnSubmission(): void
    {
        $student = $this->createUser('homework_matrix_student_own_view');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture('own_view', $student);

        $token = $this->getUserTokenFromUser($student);

        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * The core Huiswerk-specific requirement: a teacher linked to a course
     * ONLY through session A's coach role must be able to grade a submission
     * that belongs to session B of the SAME course. HomeworkCourseTeacherChecker
     * scopes at course level, deliberately broader than the default
     * ROLE_CURRENT_COURSE_SESSION_TEACHER context role (which never crosses
     * sessions).
     */
    public function testTeacherOfSessionACanGradeSubmissionFromSessionB(): void
    {
        [$course, $sessionB, $teacher, $student, $assignment, $submission] =
            $this->buildCrossSessionGradingFixture('grade');

        $em = $this->getEntityManager();
        $submissionIid = $submission->getIid();
        $assignmentIri = '/api/c_homework_assignments/'.$assignment->getIid();

        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submissionIid,
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => $assignmentIri,
                    'status' => CHomeworkSubmission::STATUS_SUBMITTED,
                    'score' => 17.5,
                    'feedback' => 'Sterk werk, verder zo!',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submissionIid);
        $this->assertNotNull($found);
        $this->assertSame(17.5, $found->getScore());
        $this->assertSame('Sterk werk, verder zo!', $found->getFeedback());
    }

    /**
     * Companion to the grading test above: the spec requires "volledige
     * inzage EN nakijkrecht" (full VIEW and grading rights), not just
     * grading. Proves the Get operation's security expression also grants
     * cross-session VIEW via HomeworkVoter, not only Put/EDIT.
     */
    public function testTeacherOfSessionACanViewSubmissionFromSessionB(): void
    {
        [$course, , $teacher, , , $submission] = $this->buildCrossSessionGradingFixture('view');

        $submissionIid = $submission->getIid();
        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submissionIid,
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * Same cross-session requirement, but on the assignment itself rather
     * than a submission - proves the fix applies uniformly across the
     * Huiswerk API resources, not only CHomeworkSubmission.
     */
    public function testTeacherOfSessionACanViewAssignmentFromSessionB(): void
    {
        [, , $teacher, , $assignment] = $this->buildCrossSessionGradingFixture('assignment_view');

        $token = $this->getUserTokenFromUser($teacher);

        // Deliberately no cid/sid query params: this keeps the request
        // focused purely on HomeworkVoter's object-level VIEW grant,
        // independent of ResourceNodeVoter's request-scoped course/session
        // role resolution (which the teacher would fail anyway, since they
        // are not a session-B coach).
        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_assignments/'.$assignment->getIid()
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * Same cross-session VIEW-wiring fix as the two tests above, applied to
     * the third affected resource: CHomeworkForm. See
     * buildCrossSessionFormFixture() for why the form's ResourceLink is
     * deliberately scoped to session B even though CHomeworkForm has no
     * domain-level session property of its own (per spec, form templates
     * are course-bound, not session-bound) - without that session-scoped
     * link, ResourceNodeVoter's own course-level grant would make this test
     * pass regardless of whether the Get-security fix is present, which
     * would not actually prove anything.
     */
    public function testTeacherOfSessionACanViewFormLinkedToSessionB(): void
    {
        [, , $teacher, $form] = $this->buildCrossSessionFormFixture('form_view');

        $token = $this->getUserTokenFromUser($teacher);

        // Deliberately no cid/sid query params, same reasoning as
        // testTeacherOfSessionACanViewAssignmentFromSessionB above.
        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_forms/'.$form->getIid()
        );

        $this->assertResponseIsSuccessful();
    }

    public function testPlatformAdminHasFullAccessRegardless(): void
    {
        $student = $this->createUser('homework_matrix_student_admin');
        // Platform admin per spec (`isSuperAdmin()` === hasRole('ROLE_GLOBAL_ADMIN')),
        // deliberately NOT subscribed to the course in any way (no
        // CourseRelUser, no session, nothing) - proves the override does not
        // depend on any course relation at all.
        $admin = $this->createUser('homework_matrix_platform_admin', '', '', 'ROLE_GLOBAL_ADMIN');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture('admin', $student);

        $this->assertTrue($admin->isSuperAdmin());

        $token = $this->getUserTokenFromUser($admin);

        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * Negative control for the cross-session grant: a teacher with NO
     * relation whatsoever to the course (not course-level, not via any
     * session) must still be denied, proving HomeworkCourseTeacherChecker
     * does not over-grant to any authenticated teacher-role user.
     */
    public function testTeacherWithNoRelationToCourseIsDenied(): void
    {
        $student = $this->createUser('homework_matrix_student_unrelated');
        $unrelatedTeacher = $this->createUser('homework_matrix_teacher_unrelated');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture('unrelated', $student);

        $token = $this->getUserTokenFromUser($unrelatedTeacher);

        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Reproduces the exact exploit found in Task 15's review: create a DRAFT
     * submission for an assignment whose deadline has passed AND
     * allowLateSubmission=false (the hard-block case, where HomeworkSubmit.vue
     * would not even render the submission UI), then PUT {"status": SUBMITTED}
     * directly as the student, bypassing the Vue component entirely.
     * CHomeworkSubmission's Put security only checks resourceNode ownership
     * (via ResourceNodeVoter), not course role or deadline, so without
     * CHomeworkSubmissionStatusResolver this returned 200 and persisted as
     * SUBMITTED with submittedAt auto-populated - a full bypass of the spec's
     * "Na de deadline: indienen geblokkeerd tenzij allowLateSubmission = true"
     * requirement, not just a DRAFT/SUBMITTED/LATE labeling nuance.
     */
    public function testStudentCannotSubmitPastHardDeadline(): void
    {
        $student = $this->createUser('homework_matrix_student_hard_deadline');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture(
            'hard_deadline',
            $student,
            new DateTime('2020-01-01'),
            false,
            CHomeworkSubmission::STATUS_DRAFT
        );

        $token = $this->getUserTokenFromUser($student);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submission->getIid(),
            [
                'query' => ['cid' => $course->getId()],
                'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED],
            ]
        );

        $this->assertResponseStatusCodeSame(422);

        $em = $this->getEntityManager();
        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertSame(
            CHomeworkSubmission::STATUS_DRAFT,
            $found->getStatus(),
            'A rejected submit attempt must not leave the row persisted as SUBMITTED.'
        );
        $this->assertNull($found->getSubmittedAt());
    }

    /**
     * Companion positive case: when allowLateSubmission=true, the same
     * client-sent status=SUBMITTED past the deadline must be SERVER-coerced
     * to STATUS_LATE - the client's requested value is only ever trusted as
     * an "I want to submit now" signal, never as the literal persisted status.
     */
    public function testStudentSubmissionPastDeadlineIsCoercedToLateWhenAllowed(): void
    {
        $student = $this->createUser('homework_matrix_student_late_allowed');

        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture(
            'late_allowed',
            $student,
            new DateTime('-1 day'),
            true,
            CHomeworkSubmission::STATUS_DRAFT
        );

        $token = $this->getUserTokenFromUser($student);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submission->getIid(),
            [
                'query' => ['cid' => $course->getId()],
                // Client sends SUBMITTED, not LATE - the server must not trust this.
                'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED],
            ]
        );

        $this->assertResponseIsSuccessful();

        $em = $this->getEntityManager();
        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertSame(CHomeworkSubmission::STATUS_LATE, $found->getStatus());
        $this->assertNotNull($found->getSubmittedAt());
    }

    /**
     * The second half of the deadline-bypass fix: once a submission is
     * already SUBMITTED, the owning student still holds EDIT on it via
     * ResourceNodeVoter's plain ownership check - without
     * CHomeworkSubmissionStatusResolver's post-submit lock, a student could
     * PUT any status value onto their own already-submitted row (e.g. back to
     * DRAFT) to hide it from a status-filtered teacher list. The status
     * change must be silently ignored (the PUT itself still succeeds - the
     * student may legitimately be updating other fields in the same
     * request), not rejected outright.
     */
    public function testStudentCannotRewriteOwnAlreadySubmittedStatus(): void
    {
        $student = $this->createUser('homework_matrix_student_status_lock');

        // Default fixture: STATUS_SUBMITTED, future deadline, allowLateSubmission=false.
        [$course, , $submission] = $this->buildSingleStudentSubmissionFixture('status_lock', $student);

        $token = $this->getUserTokenFromUser($student);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submission->getIid(),
            [
                'query' => ['cid' => $course->getId()],
                'json' => ['status' => CHomeworkSubmission::STATUS_DRAFT],
            ]
        );

        $this->assertResponseIsSuccessful();

        $em = $this->getEntityManager();
        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertSame(
            CHomeworkSubmission::STATUS_SUBMITTED,
            $found->getStatus(),
            'A student must not be able to rewrite their own already-submitted status.'
        );
    }

    /**
     * Companion to the lock test above: a course teacher (checked the same
     * way CHomeworkSubmissionExtension scopes collection visibility -
     * HomeworkCourseTeacherChecker's course-wide, cross-session check) must
     * still be able to revise an already-submitted status, e.g. to reopen a
     * submission for correction.
     */
    public function testTeacherCanStillChangeAlreadySubmittedStatus(): void
    {
        [$course, $sessionB, $teacher, , $assignment, $submission] =
            $this->buildCrossSessionGradingFixture('status_teacher_override');

        $assignmentIri = '/api/c_homework_assignments/'.$assignment->getIid();
        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submission->getIid(),
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => $assignmentIri,
                    'status' => CHomeworkSubmission::STATUS_DRAFT,
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $em = $this->getEntityManager();
        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertSame(CHomeworkSubmission::STATUS_DRAFT, $found->getStatus());
    }

    /**
     * Same self-write exploit class as testStudentCannotRewriteOwnAlreadySubmittedStatus,
     * but for $score/$feedback rather than $status: unlike $status, these two
     * fields have NO CHomeworkSubmissionStatusResolver-style guard of their
     * own by default - they carry `homework_submission:write` (needed so a
     * teacher's grading PUT can set them), and the owning student holds EDIT
     * on their own submission via the exact same ResourceNodeVoter ownership
     * check the deadline-bypass and status-rewrite exploits above rely on.
     * Confirmed via live reproduction before this test/fix existed:
     * `PUT {"score":99}` from the owning student's own session returned 200
     * and persisted - full self-grading, defeating the module's core
     * "nakijkrecht" (grading is a teacher/admin-only act) requirement.
     * CHomeworkSubmissionPutStateProcessor now reverts $score/$feedback to
     * their pre-request values whenever the requester is not a privileged
     * grader (CHomeworkSubmissionStatusResolver::resolvePrivilegedGrader()) -
     * same "silently ignore, don't reject the whole request" semantics as the
     * $status lock, so an otherwise-legitimate student PUT is never
     * hard-rejected over this.
     */
    public function testStudentCannotSelfGradeOwnSubmission(): void
    {
        $student = $this->createUser('homework_matrix_student_self_grade');

        // Default fixture: STATUS_SUBMITTED, no score/feedback set yet.
        [$course, $assignment, $submission] = $this->buildSingleStudentSubmissionFixture('self_grade', $student);
        $assignmentIri = '/api/c_homework_assignments/'.$assignment->getIid();

        $token = $this->getUserTokenFromUser($student);

        $this->createClientWithCredentials($token)->request(
            'PUT',
            '/api/c_homework_submissions/'.$submission->getIid(),
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => $assignmentIri,
                    'score' => 99,
                    'feedback' => 'Ik geef mezelf een 99.',
                ],
            ]
        );

        // Same semantics as the $status lock: the PUT itself still succeeds
        // (the student may legitimately be updating other, unrelated fields
        // in the same request) - only $score/$feedback are silently reverted.
        $this->assertResponseIsSuccessful();

        $em = $this->getEntityManager();
        $em->clear();

        /** @var CHomeworkSubmission $found */
        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertNull($found->getScore(), 'A student must not be able to set their own score.');
        $this->assertNull($found->getFeedback(), 'A student must not be able to set their own feedback.');
        $this->assertNull($found->getEvaluatedBy(), 'A rejected self-grade attempt must not stamp evaluatedBy either.');
        $this->assertNull($found->getEvaluatedAt(), 'A rejected self-grade attempt must not stamp evaluatedAt either.');
    }

    /**
     * Collection-level counterpart to the pagination-truncation fix: proves
     * the new "status" SearchFilter (src/CourseBundle/Entity/CHomeworkSubmission.php)
     * actually scopes the collection server-side. HomeworkCorrectAndRate.vue
     * relies on this (plus real page/itemsPerPage pagination) instead of
     * fetching a single page of results and filtering client-side, which
     * would silently show only a subset of submissions for any assignment
     * with more than one API Platform page's worth of them.
     */
    public function testStatusFilterScopesCollectionServerSide(): void
    {
        $studentDraft = $this->createUser('homework_matrix_student_status_filter_draft');
        $studentSubmitted = $this->createUser('homework_matrix_student_status_filter_submitted');

        [$course, $assignment, $draftSubmission] = $this->buildSingleStudentSubmissionFixture(
            'status_filter',
            $studentDraft,
            null,
            false,
            CHomeworkSubmission::STATUS_DRAFT,
        );

        $em = $this->getEntityManager();
        $submittedSubmission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($studentSubmitted)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setCreator($studentSubmitted)
        ;
        $submittedSubmission->setParent($course);
        $submittedSubmission->addUserLink($studentSubmitted, $course);
        $em->persist($submittedSubmission);
        $em->flush();

        // Course-level teacher (not the fixture's addCourseLink()-only
        // assignment owner) so the collection extension grants full,
        // cross-submission visibility rather than the single-student scoping
        // a student token would get.
        $teacher = $this->createUser('homework_matrix_teacher_status_filter');
        $course->addUserAsTeacher($teacher);
        $em->persist($course);
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions',
            [
                'query' => [
                    'cid' => $course->getId(),
                    'assignment.iid' => $assignment->getIid(),
                    'status' => CHomeworkSubmission::STATUS_DRAFT,
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $members = $data['hydra:member'] ?? [];

        $this->assertCount(1, $members, 'The "status" filter must scope the collection server-side.');
        $this->assertSame(
            '/api/c_homework_submissions/'.$draftSubmission->getIid(),
            $members[0]['@id'] ?? null
        );
    }

    /**
     * Regression guard for a gap in the cross-session-teacher coverage above:
     * every existing cross-session test up to this point deliberately omits
     * `sid` from the query (see e.g.
     * testTeacherOfSessionACanViewAssignmentFromSessionB's comment) to isolate
     * HomeworkVoter's object-level grant - but that is NOT the request shape a
     * real browser sends. chomeworksubmission.js's buildCidParams() always
     * includes `sid` whenever the current page has one in its URL, which for
     * a cross-session teacher means their OWN session (A), not the session
     * the submission actually belongs to (B). SidFilter used to apply a
     * `resource_links.session = :sid` restriction unconditionally - stacking
     * with, not merging into, CHomeworkSubmissionExtension's own privilege
     * check - so this exact request shape came back empty despite the
     * teacher's object-level access being correct. Fixed by removing
     * SidFilter from CHomeworkSubmission in favour of the privilege check
     * alone (a student's own submissions have no legitimate session-privacy
     * need in the first place - see CHomeworkSubmission's class docblock).
     */
    public function testCrossSessionTeacherSeesSubmissionCollectionWithOwnSidInQuery(): void
    {
        [$course, , $teacher, , $assignment, , $sessionA] =
            $this->buildCrossSessionGradingFixture('submission_collection_sid');

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions',
            [
                'query' => [
                    'cid' => $course->getId(),
                    'sid' => $sessionA->getId(),
                    'assignment.iid' => $assignment->getIid(),
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $members = $data['hydra:member'] ?? [];

        $this->assertNotEmpty(
            $members,
            "Cross-session teacher must see session B's submission while browsing with sid=own session (A), not just when sid is omitted entirely."
        );
    }

    /**
     * Same gap, same fix, applied to CHomeworkAssignment via
     * CHomeworkAssignmentExtension instead of CHomeworkSubmissionExtension.
     */
    public function testCrossSessionTeacherSeesAssignmentCollectionWithOwnSidInQuery(): void
    {
        [$course, , $teacher, , $assignment, , $sessionA] =
            $this->buildCrossSessionGradingFixture('assignment_collection_sid');

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_assignments',
            ['query' => ['cid' => $course->getId(), 'sid' => $sessionA->getId()]]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $iris = array_map(static fn ($a) => $a['@id'] ?? null, $data['hydra:member'] ?? []);

        $this->assertContains(
            '/api/c_homework_assignments/'.$assignment->getIid(),
            $iris,
            "Cross-session teacher must see session B's assignment while browsing with sid=own session (A)."
        );
    }

    /**
     * Same gap, same fix, applied to CHomeworkForm via CHomeworkFormExtension:
     * the course-wide teacher (privileged) path must see every session's
     * forms, including one scoped to a session they are not personally
     * linked to. See testStudentCannotSeeCrossSessionFormInCollection()
     * immediately below for the companion negative case (a non-privileged
     * student must NOT get that same course-wide view) - CHomeworkForm has no
     * domain-level session property of its own, but its ResourceLink is
     * still session-scoped whenever created from within a session context
     * (ResourceListener::normalizeSingleLinkContextFromSession(), and
     * CHomeworkForm's own Post operation explicitly allows
     * ROLE_CURRENT_COURSE_SESSION_TEACHER), so this is a real privacy
     * boundary, not just a cross-session-teacher visibility question.
     */
    public function testCrossSessionTeacherSeesFormCollectionWithOwnSidInQuery(): void
    {
        [$course, , $teacher, $form, $sessionA] = $this->buildCrossSessionFormFixture('form_collection_sid');

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_forms',
            ['query' => ['cid' => $course->getId(), 'sid' => $sessionA->getId()]]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $iris = array_map(static fn ($f) => $f['@id'] ?? null, $data['hydra:member'] ?? []);

        $this->assertContains(
            '/api/c_homework_forms/'.$form->getIid(),
            $iris,
            "Cross-session teacher must see session B's form while browsing with sid=own session (A)."
        );
    }

    /**
     * Companion negative case to the test above, and the actual regression
     * guard for the privacy gap found in review: a student who is only a
     * member of session A must NOT see a form whose ResourceLink is scoped
     * exclusively to session B, even though GetCollection's own security
     * expression (`ROLE_CURRENT_COURSE_STUDENT or
     * ROLE_CURRENT_COURSE_SESSION_STUDENT`) lets them make the request at
     * all. The response includes the full pages/fields structure
     * (labels/help text/required/options) under `homework_form:read`, so
     * this is a real content leak of another session's form template, not
     * just an existence leak. Mirrors
     * testStudentCannotListAnotherStudentsSubmissionInCollection's and
     * testStudentCannotSeeCoursemateHomeworkDocumentInGeneralDocumentsCollection's
     * pattern: the sibling submission/document collections both already have
     * this negative case; CHomeworkForm's initial cross-session fix
     * incorrectly assumed no such case was needed here.
     */
    public function testStudentCannotSeeCrossSessionFormInCollection(): void
    {
        [$course, $sessionB, , $form, $sessionA] = $this->buildCrossSessionFormFixture('form_collection_privacy');

        $student = $this->createUser('homework_matrix_student_form_collection_privacy');
        $sessionA->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($sessionA);
        $em->flush();

        $token = $this->getUserTokenFromUser($student);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_forms',
            ['query' => ['cid' => $course->getId(), 'sid' => $sessionA->getId()]]
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode($response->getContent(), true);
        $iris = array_map(static fn ($f) => $f['@id'] ?? null, $data['hydra:member'] ?? []);

        $this->assertNotContains(
            '/api/c_homework_forms/'.$form->getIid(),
            $iris,
            'A student in session A must not see a form scoped exclusively to session B in the collection.'
        );

        // Item-level GET must also stay denied (ResourceNodeVoter's own
        // session-scoped decision, independent of this collection fix).
        $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_forms/'.$form->getIid(),
            ['query' => ['cid' => $course->getId(), 'sid' => $sessionA->getId()]]
        );
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Regression guard for CreateHomeworkSubmissionFileAction (the
     * student-facing counterpart to CDocument's teacher-only /documents Post
     * operation, added because a plain student got a 403 there). The
     * ResourceLink built from the request's resourceLinkList must always be
     * owned by the AUTHENTICATED user, never whatever `uid` the client
     * attaches - buildResourceLinkListFromContext() only derives cid/sid/gid
     * from the session-resolved course, but has no concept of `uid` at all,
     * so this action adds it itself and must not trust the request for it.
     */
    public function testHomeworkSubmissionUploadForcesResourceLinkOwnerToAuthenticatedUser(): void
    {
        $course = $this->createCourse('homework_matrix_course_upload_uid');
        $session = $this->createSession('homework_matrix_session_upload_uid');
        $uploader = $this->createUser('homework_matrix_uploader_uid');
        $forgedTarget = $this->createUser('homework_matrix_forged_target_uid');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $uploader, $course);
        $session->addUserInCourse(Session::STUDENT, $forgedTarget, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($uploader);
        $file = $this->getUploadedFile();

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/documents/homework-submission-upload',
            [
                'query' => ['cid' => $course->getId(), 'sid' => $session->getId()],
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => ['files' => ['uploadFile' => $file]],
                'json' => [
                    'filetype' => 'file',
                    'parentResourceNodeId' => $resourceNodeId,
                    // Attempt to forge ownership onto a DIFFERENT student -
                    // must be ignored server-side.
                    'resourceLinkList' => [['visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $forgedTarget->getId()]],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $documentIri = $response->toArray()['@id'];
        $documentIid = (int) substr($documentIri, strrpos($documentIri, '/') + 1);

        $em->clear();

        /** @var CDocument $document */
        $document = $em->getRepository(CDocument::class)->find($documentIid);
        $this->assertNotNull($document);

        $ownerIds = [];
        foreach ($document->getResourceNode()->getResourceLinks() as $link) {
            if ($link->hasUser()) {
                $ownerIds[] = $link->getUser()->getId();
            }
        }

        $this->assertContains(
            $uploader->getId(),
            $ownerIds,
            'The ResourceLink must be owned by the AUTHENTICATED uploader.'
        );
        $this->assertNotContains(
            $forgedTarget->getId(),
            $ownerIds,
            'A client-supplied uid must never be trusted - the forged target must not end up owning the document.'
        );
    }

    /**
     * The document-metadata-leak regression this whole batch of fixes exists
     * for: DocumentCollectionStateProvider (CDocument's GetCollection
     * provider - a custom ProviderInterface that bypasses
     * QueryCollectionExtensionInterface entirely, including CDocumentExtension)
     * had no row-level ownership check at all. Harmless while the only way to
     * create a CDocument was the teacher-only /documents endpoint (nothing
     * was ever user-scoped); a real leak (filename, uploader identity, upload
     * time) once CreateHomeworkSubmissionFileAction made student-owned,
     * user-scoped documents possible. Reproduces the exact live scenario:
     * two students enrolled in the SAME session of the SAME course, one
     * plain `GET /api/documents?cid=X&sid=Y` browse - no special parameters.
     */
    public function testStudentCannotSeeCoursemateHomeworkDocumentInGeneralDocumentsCollection(): void
    {
        $course = $this->createCourse('homework_matrix_course_doc_leak');
        $session = $this->createSession('homework_matrix_session_doc_leak');
        $studentA = $this->createUser('homework_matrix_student_a_doc_leak');
        $studentB = $this->createUser('homework_matrix_student_b_doc_leak');
        $teacher = $this->createUser('homework_matrix_teacher_doc_leak');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $studentA, $course);
        $session->addUserInCourse(Session::STUDENT, $studentB, $course);
        $course->addUserAsTeacher($teacher);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->persist($course);
        $em->flush();

        // Direct entity fixture (the HTTP upload path is covered by
        // testHomeworkSubmissionUploadForcesResourceLinkOwnerToAuthenticatedUser
        // above): a user-scoped document exactly like a real homework
        // submission file, owned by student A only.
        $document = (new CDocument())
            ->setFiletype('file')
            ->setTitle('doc_leak_evidence.txt')
            ->setCreator($studentA)
        ;
        $document->setParent($course);
        $document->addUserLink($studentA, $course, $session);
        $em->persist($document);
        $em->flush();

        $query = ['cid' => $course->getId(), 'sid' => $session->getId(), 'itemsPerPage' => 5000];

        // studentB (coursemate, same session) must NOT see it.
        $tokenB = $this->getUserTokenFromUser($studentB);
        $responseB = $this->createClientWithCredentials($tokenB)->request('GET', '/api/documents', ['query' => $query]);
        $this->assertResponseIsSuccessful();
        $titlesB = array_map(static fn ($d) => $d['title'] ?? null, json_decode($responseB->getContent(), true)['hydra:member'] ?? []);
        $this->assertNotContains(
            'doc_leak_evidence.txt',
            $titlesB,
            "A student must not see another student's user-scoped (homework submission) document in the general /api/documents collection."
        );

        // Item-level GET must also stay denied (already true before this fix - confirms no regression there).
        $this->createClientWithCredentials($tokenB)->request('GET', '/api/documents/'.$document->getIid(), ['query' => ['cid' => $course->getId()]]);
        $this->assertResponseStatusCodeSame(403);

        // studentA (the owner) must still see their own document.
        $tokenA = $this->getUserTokenFromUser($studentA);
        $responseA = $this->createClientWithCredentials($tokenA)->request('GET', '/api/documents', ['query' => $query]);
        $titlesA = array_map(static fn ($d) => $d['title'] ?? null, json_decode($responseA->getContent(), true)['hydra:member'] ?? []);
        $this->assertContains('doc_leak_evidence.txt', $titlesA, 'The owning student must still see their own document.');

        // The course teacher must still see it too (same bypass as DRAFT visibility).
        $tokenTeacher = $this->getUserTokenFromUser($teacher);
        $responseTeacher = $this->createClientWithCredentials($tokenTeacher)->request('GET', '/api/documents', ['query' => $query]);
        $titlesTeacher = array_map(static fn ($d) => $d['title'] ?? null, json_decode($responseTeacher->getContent(), true)['hydra:member'] ?? []);
        $this->assertContains('doc_leak_evidence.txt', $titlesTeacher, 'A course teacher must still see a student document.');
    }

    /**
     * Shared fixture for the cross-session-teacher tests: a course with two
     * sessions (A and B), a teacher who is course coach of session A ONLY
     * (Session::addUserInCourse(COURSE_COACH, ...) - no course-level
     * CourseRelUser::TEACHER, no relation to session B at all), a student
     * enrolled in session B, an assignment scoped to session B, and that
     * student's submission to it.
     *
     * @return array{0: Course, 1: Session, 2: User, 3: User, 4: CHomeworkAssignment, 5: CHomeworkSubmission, 6: Session}
     *               Element 6 is $sessionA (the teacher's own session) - appended
     *               rather than inserted, so every existing positional-destructure
     *               caller keeps working unchanged; only callers that need the
     *               teacher's own session (to reproduce the real request shape a
     *               browser sends: `sid` = the CURRENT session the teacher is
     *               browsing from, not the session the assignment/submission
     *               actually belongs to) capture it explicitly.
     */
    private function buildCrossSessionGradingFixture(string $suffix): array
    {
        $course = $this->createCourse('homework_matrix_course_'.$suffix);
        $sessionA = $this->createSession('homework_matrix_session_a_'.$suffix);
        $sessionB = $this->createSession('homework_matrix_session_b_'.$suffix);
        $teacher = $this->createUser('homework_matrix_teacher_'.$suffix);
        $student = $this->createUser('homework_matrix_student_'.$suffix);

        $sessionA->addCourse($course);
        $sessionB->addCourse($course);

        $sessionA->addUserInCourse(Session::COURSE_COACH, $teacher, $course);
        $sessionB->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($sessionA);
        $em->persist($sessionB);
        $em->flush();

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag sessie B - '.$suffix)
            ->setSession($sessionB)
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline(new DateTime('2026-08-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_SCORE)
            ->setCreator($this->getAdmin())
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course, $sessionB);
        $em->persist($assignment);

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setCreator($student)
        ;
        $submission->setParent($course);
        $submission->addUserLink($student, $course, $sessionB);
        $em->persist($submission);
        $em->flush();

        return [$course, $sessionB, $teacher, $student, $assignment, $submission, $sessionA];
    }

    /**
     * Shared fixture for the single-student, course-level-assignment tests
     * (student isolation, own-submission view, platform-admin override,
     * unrelated-teacher denial, deadline enforcement, post-submit status
     * lock): a course, a course-linked assignment, and one submission owned
     * by $owner via a user-scoped ResourceLink. See the class docblock for
     * why addUserLink() (not addCourseLink()) is what actually keeps the
     * submission private to $owner.
     *
     * Defaults reproduce the original always-submitted fixture exactly
     * (deadline far in the future, STATUS_SUBMITTED, submittedAt "now") so
     * every pre-existing caller is unaffected; the deadline-enforcement and
     * post-submit-lock tests override $deadline/$allowLateSubmission/$status
     * explicitly instead of duplicating this whole fixture.
     *
     * @return array{0: Course, 1: CHomeworkAssignment, 2: CHomeworkSubmission}
     */
    private function buildSingleStudentSubmissionFixture(
        string $suffix,
        User $owner,
        ?DateTime $deadline = null,
        bool $allowLateSubmission = false,
        int $status = CHomeworkSubmission::STATUS_SUBMITTED,
        ?DateTime $submittedAt = null,
    ): array {
        $deadline ??= new DateTime('2026-08-01 23:59:00');
        // Only auto-fill "now" for a non-draft status when the caller didn't
        // pass one explicitly - a DRAFT fixture (used by the deadline tests)
        // must stay unsubmitted, i.e. submittedAt still null.
        if (null === $submittedAt && CHomeworkSubmission::STATUS_DRAFT !== $status) {
            $submittedAt = new DateTime();
        }

        $course = $this->createCourse('homework_matrix_course_'.$suffix);

        $em = $this->getEntityManager();

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag - '.$suffix)
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline($deadline)
            ->setAllowLateSubmission($allowLateSubmission)
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_STATUS_ONLY)
            ->setCreator($this->getAdmin())
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($owner)
            ->setStatus($status)
            ->setSubmittedAt($submittedAt)
            ->setCreator($owner)
        ;
        $submission->setParent($course);
        $submission->addUserLink($owner, $course);
        $em->persist($submission);
        $em->flush();

        return [$course, $assignment, $submission];
    }

    /**
     * Shared fixture for the cross-session-teacher CHomeworkForm test: a
     * course with two sessions (A and B), a teacher who is course coach of
     * session A ONLY, and a CHomeworkForm whose ResourceLink is scoped to
     * session B specifically (addCourseLink($course, $sessionB)) - the same
     * session-scoping technique used by buildCrossSessionGradingFixture()
     * above, so that ResourceNodeVoter's own (CURRENT session-scoped)
     * decision denies and only HomeworkVoter's cross-session, course-wide
     * grant can succeed.
     *
     * @return array{0: Course, 1: Session, 2: User, 3: CHomeworkForm, 4: Session}
     *               Element 4 is $sessionA (the teacher's own session) -
     *               appended for the same reason as
     *               buildCrossSessionGradingFixture()'s element 6.
     */
    private function buildCrossSessionFormFixture(string $suffix): array
    {
        $course = $this->createCourse('homework_matrix_course_'.$suffix);
        $sessionA = $this->createSession('homework_matrix_session_a_'.$suffix);
        $sessionB = $this->createSession('homework_matrix_session_b_'.$suffix);
        $teacher = $this->createUser('homework_matrix_teacher_'.$suffix);

        $sessionA->addCourse($course);
        $sessionB->addCourse($course);
        $sessionA->addUserInCourse(Session::COURSE_COACH, $teacher, $course);

        $em = $this->getEntityManager();
        $em->persist($sessionA);
        $em->persist($sessionB);
        $em->flush();

        $form = (new CHomeworkForm())
            ->setTitle('Lesverslag sjabloon - '.$suffix)
            ->setCreator($this->getAdmin())
        ;
        $form->setParent($course);
        $form->addCourseLink($course, $sessionB);
        $em->persist($form);
        $em->flush();

        return [$course, $sessionB, $teacher, $form, $sessionA];
    }
}
