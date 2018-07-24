@mod @mod_forum
Feature: Students can edit or delete their forum inline posts within a set time limit
  In order to refine forum posts
  As a user
  I need to edit or delete my forum posts

  Background:
    Given the following config values are set as admin:
      | config                    | value |
      | forum_enablequotedreplies | 1     |
      | forum_enableinlineediting | 1     |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name            | intro                  | course | idnumber |
      | forum    | Test forum name | Test forum description | C1     | forum    |
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And I add a new discussion to "Test forum name" forum with:
      | Subject | Forum post subject |
      | Message | This is the body   |

  @javascript
  Scenario: Edit forum post
    Given I follow "Forum post subject"
    And I follow "Edit"
    When I set the following fields to these values:
      | Subject | Edited post subject |
      | Message | Edited post body    |
    And I click on "Post to forum" "button"
    Then I should see "Edited post subject"
    And I should see "Edited post body"

  @javascript
  Scenario: Reply forum post
    Given I follow "Forum post subject"
    And I follow "Reply"
    When I set the following fields to these values:
      | Message | Replied post body |
    And I click on "Post to forum" "button"
    Then I should see "Re: Forum post subject"
    And I should see "Replied post body"

  @javascript
  Scenario: Quote forum post
    Given I follow "Forum post subject"
    And I follow "Quote"
    And I click on "Post to forum" "button"
    Then I should see "Re: Forum post subject"
    Then "blockquote" "text" should exist
