Feature: Homework tool
  In order to use the homework tool
  Teachers should be able to create homework assignments (file or form based)
  and students should be able to submit and be graded

  # These scenarios are ordered so a teacher-only assignment ("Homework File 1")
  # exists on its own for scenarios 1-5, before the form-based scenario adds a
  # second row to the list - HomeworkList.vue has no per-row link/anchor to
  # disambiguate rows by title (unlike the legacy Work module's "a.text-blue-600"
  # title link), only icon-class action buttons shared by every row, so keeping
  # a single assignment on screen for those scenarios avoids ambiguity.

  Scenario: Teacher creates a homework assignment (file-based)
    Given I am a platform administrator
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I click the "span.mdi-plus" element
    And I wait for the page to be loaded
    When I fill in the following:
      | title | Homework File 1 |
    # Checks "Allow late submission" (the first checkbox on the form) so the
    # student's submission below is never blocked by the default deadline
    # (HomeworkAssignmentForm.vue's assignment.deadline defaults to "now").
    Then I click the "input.p-checkbox-input" element
    And I click the "span.mdi-content-save" element
    And wait very long for the page to be loaded
    Then I should see "Assignment created"
    And I should not see an error

  Scenario: Student submits a file-based homework assignment
    Given I am a platform administrator
    And I am not logged
    And I am a student
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I should see "Homework File 1"
    Then I click the "span.mdi-upload" element
    And wait very long for the page to be loaded
    Then I attach the file "/public/favicon.ico" to "homework-submission-file"
    And wait very long for the page to be loaded
    Then I click the "span.mdi-send" element
    And wait very long for the page to be loaded
    Then I should see "Submission sent"
    And I should not see an error

  Scenario: Admin views submission list for Homework File 1
    Given I am a platform administrator
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I should see "Homework File 1"
    Then I click the "span.mdi-check-circle" element
    And wait very long for the page to be loaded
    Then I should see "Costea Andrea"

  Scenario: Admin grades the submission for Homework File 1
    Given I am a platform administrator
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I click the "span.mdi-check-circle" element
    And wait very long for the page to be loaded
    Then I should see "Costea Andrea"
    Then I click the "span.mdi-check-circle" element
    And I wait for the page to be loaded
    When I fill in the following:
      | homework-score | 20 |
      | homework-feedback | Well done |
    Then I click the "span.mdi-content-save" element
    And wait very long for the page to be loaded
    Then I should see "Grade saved"
    And I should not see an error

  Scenario: Student sees graded score for Homework File 1
    Given I am a platform administrator
    And I am not logged
    And I am a student
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I should see "Homework File 1"
    Then I click the "span.mdi-upload" element
    And wait very long for the page to be loaded
    Then I should see "Score: 20"

  Scenario: Teacher creates a homework assignment (form-based)
    Given I am a platform administrator
    And I am on course "TEMP" homepage
    And I wait for the page to be loaded
    And I zoom out to maximum
    And I follow "Homework"
    And wait very long for the page to be loaded
    Then I click the "span.mdi-plus" element
    And I wait for the page to be loaded
    # Switch the assignment's submission type from the default "File" to "Form".
    Then I click the "label[for='submission_type-1']" element
    And I wait for the page to be loaded
    # "Create new form" (only "+" button visible once the Form radio is
    # selected) opens the drag-and-drop form builder.
    Then I click the "span.mdi-plus" element
    And wait very long for the page to be loaded
    When I fill in the following:
      | homework-form-title | Homework Form 1 |
    # The builder auto-adds one page (pre-filled title "Page 1") on load, so
    # only a field needs to be added to it: click the "Text" field-type button.
    Then I click the "i.mdi-file-document" element
    And I wait for the page to be loaded
    When I fill in the following:
      | Label | Answer |
    Then I click the "span.mdi-content-save" element
    And wait very long for the page to be loaded
    Then I should see "Form saved"
    # Saving returns to the assignment-create screen, which resets: re-fill it.
    When I fill in the following:
      | title | Homework Form Assignment 1 |
    Then I click the "input.p-checkbox-input" element
    And I click the "label[for='submission_type-1']" element
    And I wait for the page to be loaded
    Then I click the "#homework-form-id" element
    And I wait for the page to be loaded
    Then I click the "li.p-select-option" element
    And I wait for the page to be loaded
    Then I click the "span.mdi-content-save" element
    And wait very long for the page to be loaded
    Then I should see "Assignment created"
    And I should not see an error
