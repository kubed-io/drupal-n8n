Feature: N8N Connection as an AI Provider 
  As a Drupal Admin 
  I want to configure the n8n connection as an AI provider
  So that I can register which workflows to use and verify the connection is valid. 

  Background:
    Given the app is installed and enabled

  Scenario: Set up and verify the connection
    When an admin configures the n8n with: 
      | base URL | https://n8n.example.com |
      | API key  | my-secret-api-key       |
      | Tag      | mysite                  | 
      | timeout  | 30                      |
    And the admin tests the connection
    Then the connection is reported as successful 
    And the "n8n" provider is now a default option for these capabilities: 
      | capability |
      | chat       |
    And the assistants now offer "n8n" as a provider


