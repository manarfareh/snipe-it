Feature: User Management API
  As an IT administrator
  I want to manage users via the Snipe-IT REST API
  So that I can assign assets to the correct people

  Background:
    Given I am authenticated as an admin via API token

  Scenario: List users returns paginated results
    When I send a GET request to "/api/v1/users"
    Then the response status code should be 200
    And the response should contain a "rows" array
    And the response should contain a "total" field

  Scenario: Create a new user
    When I send a POST request to "/api/v1/users" with body:
      """
      {
        "first_name": "Acceptance",
        "last_name": "TestUser",
        "username": "acceptance.testuser",
        "password": "SecureP@ssword1",
        "password_confirmation": "SecureP@ssword1",
        "email": "acceptance.testuser@example.com",
        "activated": true
      }
      """
    Then the response status code should be 200
    And the response payload should contain "status" equal to "success"
    And the created user should have "username" equal to "acceptance.testuser"

  Scenario: Retrieve user profile
    Given user "acceptance.testuser" exists
    When I send a GET request to "/api/v1/users?username=acceptance.testuser"
    Then the response status code should be 200
    And the response should contain a "rows" array with at least 1 item

  Scenario: Non-admin cannot access user list
    Given I am authenticated as a regular user
    When I send a GET request to "/api/v1/users"
    Then the response status code should be 403
