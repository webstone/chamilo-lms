<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\Tool;
use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Chamilo\CourseBundle\Entity\CTool;
use Doctrine\DBAL\Schema\Schema;

/**
 * Backfills the huiswerk (Homework) course tool onto every course that
 * already existed before this module was introduced - new courses get it
 * automatically via ToolChain::addToolsInCourse(), but that only runs at
 * course-creation time, so it never reaches courses created earlier.
 *
 * Deliberately adds it as DRAFT (inactive) for every course, not published -
 * this migration has no way to know which specific production courses
 * should have Homework turned on from day one. A course teacher/admin can
 * publish it per course afterwards via the course home's tool visibility
 * toggle (the eye icon), same as any other tool.
 */
final class Version20260715163000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Adds the huiswerk tool (inactive) to every pre-existing course that does not have it yet, positioned right after dropbox.';
    }

    public function up(Schema $schema): void
    {
        $em = $this->entityManager;
        $huiswerkTool = $em->getRepository(Tool::class)->findOneBy(['title' => 'huiswerk']);

        if (!$huiswerkTool) {
            error_log('[MIGRATION] Tool "huiswerk" not found - skipping backfill (was ToolChain::createTools() run yet?).');

            return;
        }

        $courseIds = $this->connection->fetchFirstColumn('SELECT id FROM course ORDER BY id');
        $added = 0;

        foreach ($courseIds as $rawCourseId) {
            $courseId = (int) $rawCourseId;

            $alreadyHasIt = $this->connection->fetchOne(
                'SELECT 1 FROM c_tool WHERE c_id = ? AND title = ? LIMIT 1',
                [$courseId, 'huiswerk']
            );
            if (false !== $alreadyHasIt) {
                continue;
            }

            $course = $this->findCourse($courseId);
            if (!$course) {
                continue;
            }

            $courseTool = (new CTool())
                ->setTool($huiswerkTool)
                ->setTitle('huiswerk')
                ->setParent($course)
                ->setCreator($course->getCreator())
                ->addCourseLink($course, null, null, ResourceLink::VISIBILITY_DRAFT)
            ;
            $course->addTool($courseTool);
            $em->persist($courseTool);
            $em->flush();

            // Gedmo\SortablePosition just appended it at the end of this
            // course's tool list (see ToolChain::addToolsInCourse()'s own
            // docblock for the same caveat) - move it to right after
            // dropbox, shifting whatever was in between up by one.
            $dropboxPosition = $this->connection->fetchOne(
                'SELECT position FROM c_tool WHERE c_id = ? AND title = ? LIMIT 1',
                [$courseId, 'dropbox']
            );
            $targetPosition = false !== $dropboxPosition
                ? (int) $dropboxPosition + 1
                : (int) $this->connection->fetchOne(
                    'SELECT COALESCE(MIN(position), 0) FROM c_tool WHERE c_id = ? AND title = ?',
                    [$courseId, 'huiswerk']
                );

            if (false !== $dropboxPosition) {
                $this->connection->executeStatement(
                    'UPDATE c_tool SET position = position + 1 WHERE c_id = ? AND title != ? AND position >= ? ORDER BY position DESC',
                    [$courseId, 'huiswerk', $targetPosition]
                );
                $this->connection->executeStatement(
                    'UPDATE c_tool SET position = ? WHERE c_id = ? AND title = ?',
                    [$targetPosition, $courseId, 'huiswerk']
                );
            }
            // No dropbox row on this course at all (very old/customized
            // course): leave huiswerk wherever Gedmo appended it rather than
            // guessing a position relative to a tool that doesn't exist here.

            ++$added;
        }

        error_log("[MIGRATION] Added huiswerk tool to {$added} pre-existing course(s).");
    }

    public function down(Schema $schema): void
    {
        // Not safely reversible: rows this migration added are indistinguishable
        // from ones a teacher/course-creation flow may have added normally in
        // the meantime. Manual cleanup only, if ever truly needed.
    }
}
