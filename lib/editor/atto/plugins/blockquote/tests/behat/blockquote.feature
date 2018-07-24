@editor @editor_atto @atto @atto_blockquote
Feature: Atto blockquote text
  To format text in Atto,
  I need to use the blockquote button.

  Background:
    Given the following config values are set as admin:
      | config  | value                 | plugin      |
      | toolbar | blockquote=blockquote | editor_atto |

  @javascript
  Scenario: Block quote some text
    Given I log in as "admin"
    And I open my profile in edit mode
    And I set the field "Description" to "<div><p>Test</p></div>"
    And I select the text in the "Description" Atto editor
    When I click on "Quote" "button"
    And I press "Update profile"
    And I follow "View profile"
    Then "blockquote" "text" should exist
