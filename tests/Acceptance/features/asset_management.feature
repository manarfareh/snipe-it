Feature: Asset Management API
  As an IT administrator using Snipe-IT
  I want to manage hardware assets via the REST API
  So that I can track company equipment across the organisation

  Background:
    Given I am authenticated as an admin via API token

  # ── Happy Path ───────────────────────────────────────────────────────────────

  Scenario: List assets returns a paginated response
    When I send a GET request to "/api/v1/hardware"
    Then the response status code should be 200
    And the response should contain a "rows" array
    And the response should contain a "total" field

  Scenario: Retrieve a single asset by ID
    Given asset with ID 1 exists in the system
    When I send a GET request to "/api/v1/hardware/1"
    Then the response status code should be 200
    And the response should contain an "id" field equal to 1

  Scenario: Create a new asset
    Given a valid asset category exists
    And a valid asset model exists
    And a valid status label exists
    When I send a POST request to "/api/v1/hardware" with body:
      """
      {
        "asset_tag": "TEST-K6-001",
        "model_id": 1,
        "status_id": 1,
        "name": "Acceptance Test Laptop"
      }
      """
    Then the response status code should be 200
    And the response payload should contain "status" equal to "success"
    And the created asset should have "asset_tag" equal to "TEST-K6-001"

  Scenario: Check out an asset to a user
    Given asset "TEST-K6-001" exists and has status "Ready to Deploy"
    And user with ID 1 exists
    When I send a POST request to "/api/v1/hardware/1/checkout" with body:
      """
      {
        "checkout_to_type": "user",
        "assigned_user": 1,
        "note": "Acceptance test checkout"
      }
      """
    Then the response status code should be 200
    And the response payload should contain "status" equal to "success"

  Scenario: Check in an asset
    Given asset with ID 1 is currently checked out
    When I send a POST request to "/api/v1/hardware/1/checkin" with body:
      """
      {
        "note": "Acceptance test checkin"
      }
      """
    Then the response status code should be 200
    And the response payload should contain "status" equal to "success"

  # ── Error / Edge Cases ────────────────────────────────────────────────────────

  Scenario: Access denied without authentication
    Given I am not authenticated
    When I send a GET request to "/api/v1/hardware"
    Then the response status code should be 401

  Scenario: Request non-existent asset returns 404
    When I send a GET request to "/api/v1/hardware/99999999"
    Then the response status code should be 200
    And the response payload should contain "status" equal to "error"

  Scenario: Create asset with missing required fields returns validation error
    When I send a POST request to "/api/v1/hardware" with body:
      """
      {
        "name": "Missing model and status"
      }
      """
    Then the response status code should be 200
    And the response payload should contain "status" equal to "error"
    And the response payload should contain validation messages
