Feature: Chatting with an n8n workflow
  As a site visitor
  I want to chat with the site's assistant
  So that I get an answer from an n8n agent, not knowing n8n produced it

  Background:
    Given the app is installed and enabled
    And the connection to n8n is verified

  @user @ui
  Scenario: The assistant's definition rides along with the visitor's message
    Given an assistant with the following values:
      | provider         | n8n              |
      | model            | Support Triage   |
      | instructions     | Answer in German |
      | agents to use    | taxonomy_agent   |
      | context length   | 10               |
      | forward identity | on               |
    And an editor named "kim" is reading "/blog/how-we-work"
    When a user asks the assistant "who wrote this?"
    Then the assistant responds with "This page was written by the marketing team."
    And the agent was handed this context:
      | source         | drupal                             |
      | site           | the site's name                    |
      | assistant      | the assistant's id                 |
      | instructions   | the assistant's                    |
      | context_window | the assistant's                    |
      | agents         | the ticked agents, as MCP tool ids |
      | user           | kim                                |
      | user_roles     | kim's roles                        |
      | allowed_roles  | the assistant's                    |
      | path           | /blog/how-we-work                  |
      | entity         | that page's node                   |
