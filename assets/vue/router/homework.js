export default {
  path: "/resources/homework/:node(\\d+)",
  meta: { requiresAuth: true, showBreadcrumb: true },
  name: "homework",
  component: () => import("../components/homework/HomeworkLayout.vue"),
  redirect: { name: "HomeworkList" },
  children: [
    {
      name: "HomeworkList",
      path: "",
      meta: { breadcrumb: "Homework" },
      component: () => import("../views/homework/HomeworkList.vue"),
    },
    {
      name: "HomeworkAssignmentCreate",
      path: "new",
      meta: { breadcrumb: "Create" },
      component: () => import("../views/homework/HomeworkAssignmentCreate.vue"),
    },
    {
      name: "HomeworkAssignmentEdit",
      path: ":assignmentId(\\d+)/edit",
      // requiresCourseTeacher: same defense-in-depth pattern as
      // HomeworkCorrectAndRate below - HomeworkList.vue only hides the
      // "Edit" link client-side (v-if="isTeacher"), which does not stop a
      // student from navigating to this path directly. The backend PUT
      // (HomeworkVoter::EDIT) remains the authoritative check; this only
      // prevents a non-teacher from ever reaching the edit UI.
      meta: { breadcrumb: "Edit assignment", requiresCourseTeacher: true },
      component: () => import("../views/homework/HomeworkAssignmentEdit.vue"),
    },
    {
      name: "HomeworkSubmit",
      path: ":assignmentId(\\d+)/submit",
      meta: { breadcrumb: "Submit" },
      component: () => import("../views/homework/HomeworkSubmit.vue"),
    },
    {
      name: "HomeworkCorrectAndRate",
      path: ":assignmentId(\\d+)/correct",
      // requiresCourseTeacher: defense-in-depth (see router/index.js's global
      // beforeResolve guard) - HomeworkList.vue only hides the "Review" link
      // client-side (v-if="isTeacher"), which does not stop a student from
      // navigating to this path directly. The backend (HomeworkVoter /
      // CHomeworkSubmissionStatusResolver) remains the authoritative check;
      // this only prevents a non-teacher from ever seeing the grading UI.
      meta: { breadcrumb: "Review", requiresCourseTeacher: true },
      component: () => import("../views/homework/HomeworkCorrectAndRate.vue"),
    },
    {
      name: "HomeworkFormBuilder",
      path: "form/:formId(\\d+)?/edit",
      meta: { breadcrumb: "Form builder" },
      component: () => import("../views/homework/HomeworkFormBuilder.vue"),
    },
  ],
}
