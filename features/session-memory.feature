# The session contract. Each Drupal conversation maps to one n8n session, so the
# agent's own memory node threads it — exactly as it would in n8n's own chat window.
#
# WHERE THE SESSION ID COMES FROM. Drupal's assistant runner mints a thread key and
# tags every provider call `ai_agents_thread_<key>`. We strip the prefix and send the
# key to n8n as `sessionId`. A memory node with Session ID "from the connected chat
# trigger" then keys on it automatically. This is the same shape as n8n's own embed
# widget, @n8n/chat, which generates a `sessionId` and sends it with every message —
# the only difference is the source: @n8n/chat keeps its id in the browser's
# localStorage, Drupal keeps its thread key in a server-side session store tied to the
# visitor's session cookie. Both are "one session per browser," and both let n8n's
# memory thread the conversation. We are the @n8n/chat widget, sourced from Drupal.
#
# THE MEMORY LIVES IN n8n, NOT DRUPAL. Drupal replays no history — it sends the newest
# message plus the session id. Replaying would make the agent see every message twice,
# because n8n already has it. What it CAN send is a hint: the assistant's History
# context length rides in `metadata.context_window`, so a memory node can size its
# Context Window Length from Drupal's setting.
#
# LOAD PREVIOUS SESSION IS NOT OUR CONCERN. The chat trigger's "Load Previous Session"
# option — From Memory or Manually — is how n8n's own chat UI rehydrates history when
# you reopen the window. Drupal drives the webhook directly with sendMessage and never
# calls loadPreviousSession, so the setting does not affect us. Threading comes from
# the memory node wired to the AGENT, not the trigger.
#
# WHAT IS NOT TESTED HERE. Whether the same browser keeps the same session across page
# loads is Drupal's server-side session behaviour (like @n8n/chat's localStorage), not
# reproducible in a headless suite — so it is documented, not asserted. What we assert
# is our part: the key we are handed becomes the session id, the newest message is the
# only message, and the history length rides the metadata. "Echo Agent" proves each by
# handing back what it received.

Feature: Conversations are threaded, and Drupal's session settings ride along
  As a site visitor
  I want my conversation threaded and sized the way the assistant was configured
  So that the agent remembers what it should and no more

  Background:
    Given the connection to n8n is configured and verified
    And the site tag is set to "mysite"

  # The bridge, our core job: the thread key we are handed is the session id n8n sees.
  Scenario: The conversation's session id reaches n8n unchanged
    When a message is sent to the "Echo Agent" agent through the provider
    Then n8n received the session id "behat-signature"

  # C1: even mid-conversation, only the newest message travels — n8n's memory has the
  # rest. The provider is handed a multi-turn history and sends just the last message.
  Scenario: Only the newest message is sent, never the replayed history
    When the newest message "the latest question" is sent to the "Echo Agent" agent after earlier turns
    Then n8n received the message "the latest question" as the whole conversation

  # The metadata flow this feature is really about: Drupal's History context length
  # becomes n8n's Context Window Length, so the admin sizes memory from Drupal.
  Scenario: The assistant's history length reaches n8n as the context window
    Given an assistant "Deep" backed by the "Echo Agent" agent with history context length 8
    When a visitor chats "hello" with the assistant "Deep"
    Then n8n received the context window 8

  # A history length of zero forwards nothing — the memory node keeps its own default.
  Scenario: An assistant with a zero history length forwards no context window
    Given an assistant "Plain" backed by the "Echo Agent" agent with history context length 0
    When a visitor chats "hello" with the assistant "Plain"
    Then n8n received no context window
