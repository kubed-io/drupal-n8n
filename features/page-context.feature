# Where the visitor is — the page the chat box is on, and the content on it.
#
# THE CONTRACT. The chat box knows which page it is embedded on, and that page's
# path travels as metadata.path (e.g. /about, /blog/how-we-work, /user/1). When the
# page IS a single piece of content, metadata.entity carries its {type, id} — so an
# agent can look up the exact node the visitor is reading, over MCP. On a listing,
# a view, the front page, or an admin route — where no single entity owns the page —
# metadata.entity is absent.
#
# WHERE THE FACT COMES FROM. Unlike the visitor's identity, the page is NOT known to
# the provider directly — when chat() runs it is handling the chat POST, not the page.
# The path arrives through Drupal's chat context, the bundle the chat block already
# sends, handed to us by AiAssistantPassContextToAgentEvent just before the message
# goes out; entity is then derived server-side from that path.
#
# THIS FEATURE IS THE HOME FOR THAT CHAT-CONTEXT OBJECT. Today the bundle carries the
# page path and NOTHING ELSE, so this feature covers path and its derived entity and
# invents nothing. When Drupal grows the context upstream, new keys earn their
# scenarios here — keeping this spec the honest record of what the injector carries.
#
# The Echo Agent hands back everything it received, which is how the suite asserts
# both what we sent and what we did NOT send.

Feature: The message can carry which page the visitor is on
  As a workflow author
  I want the page path and its content entity as context
  So that my agent can answer about the very thing the visitor is looking at

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The base case: a visitor on a content page — the path and the entity both arrive.
  Scenario: A content page forwards its path and entity
    Given an assistant "Helper" backed by the "Echo Agent" agent
    And the chat box is on the page "/node/5" for the node 5
    When a visitor chats "summarise this" with the assistant "Helper"
    Then n8n received the path "/node/5"
    And n8n received the entity of type "node" with id "5"

  # A listing has no single entity — the path travels, the entity does not.
  Scenario: A listing forwards a path but no entity
    Given an assistant "Helper" backed by the "Echo Agent" agent
    And the chat box is on the listing page "/blog"
    When a visitor chats "what's here" with the assistant "Helper"
    Then n8n received the path "/blog"
    And n8n received no entity from Drupal

  # Path is the only context key we carry; nothing else is invented.
  Scenario: Only the path and its derived entity are carried
    Given an assistant "Helper" backed by the "Echo Agent" agent
    And the chat box is on the page "/node/5" for the node 5
    When a visitor chats "hello" with the assistant "Helper"
    Then n8n received only the path and entity as page context
