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
# WHO OWNS THE SHOWN TRANSCRIPT — a per-assistant choice. The "Allow history" setting
# decides who owns the transcript the chat box repaints on reopen, and there are two
# owners. The two Session modes keep Drupal's own copy in the visitor's session store;
# a memory node wired only to the chat trigger for n8n's own UI is IGNORED, and no
# memory node is needed for the box to repaint. The third mode, "Session (from n8n
# memory)", flips ownership to n8n: Drupal stores nothing and, on open, asks the
# workflow to hand back the conversation for this session — so Drupal and n8n show one
# transcript instead of two. That mode is the one place we DO drive n8n's
# loadPreviousSession, and it only works against a RETRIEVING memory on the agent
# (Postgres, or a workflow that answers the call by hand); against Simple Memory or no
# memory it returns nothing and the box opens empty.
#
# LOAD PREVIOUS SESSION, WHEN IT IS OURS. The chat trigger's "Load Previous Session"
# option — From Memory or Manually — is how n8n rehydrates history. On the two Session
# modes Drupal never calls it, so the setting does not affect us. On "Session (from n8n
# memory)" Drupal posts loadPreviousSession with the session id and paints the box from
# n8n's `{data:[…]}` reply. Threading of the agent's own recall still comes from the
# memory node wired to the AGENT, independent of this display choice.
#
# WHAT IS NOT TESTED HERE. Whether the same browser keeps the same session across page
# loads is Drupal's server-side session behaviour (like @n8n/chat's localStorage), not
# reproducible in a headless suite — so it is documented, not asserted. What we assert
# is our part: the key we are handed becomes the session id, the newest message is the
# only message sent, the history length rides the metadata, and — for the n8n-memory
# mode — the chat box is rehydrated from what n8n hands back. "Echo Agent" proves the
# send side by handing back what it received; "History Agent" proves the load side by
# answering loadPreviousSession with a known transcript.

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

  # ── Session from n8n memory: the third Allow history mode ────────────────────
  # Drupal stops keeping its own transcript and paints the chat box from what the
  # workflow hands back. The History Agent fixture answers loadPreviousSession by
  # hand — a stand-in for a retrieving memory like Postgres Chat Memory.

  # The core of the feature: on open, the box is rehydrated from n8n, not Drupal.
  Scenario: The chat box is rehydrated from n8n's own memory
    Given an assistant "Recall" backed by the "History Agent" agent remembering from n8n
    When the assistant's stored conversation is loaded
    Then the conversation came back from n8n including "turn three question"
    And the conversation came back from n8n including "turn three answer"
    And every loaded turn can be shown in the chat box

  # Sizing still applies: History context length trims how many past turns return,
  # exactly as it bounds a Drupal-stored transcript — here length 1 keeps 3 messages.
  Scenario: History context length trims what n8n hands back
    Given an assistant "RecallShort" backed by the "History Agent" agent remembering from n8n with history context length 1
    When the assistant's stored conversation is loaded
    Then the loaded conversation has 3 messages

  # The default length of 2 keeps the last five messages of a six-message transcript.
  Scenario: The default history length keeps the last five turns
    Given an assistant "RecallDefault" backed by the "History Agent" agent remembering from n8n
    When the assistant's stored conversation is loaded
    Then the loaded conversation has 5 messages

  # Regression: sourcing the transcript from n8n must not swallow the live question.
  # getMessageHistory feeds the provider too, so it has to end with the new message —
  # otherwise the workflow is asked nothing and never runs at all.
  Scenario: A live question in n8n-memory mode still reaches the workflow
    Given an assistant "LiveAsk" backed by the "Echo Agent" agent remembering from n8n
    When a visitor chats "does this reach n8n" with the assistant "LiveAsk"
    Then n8n received the message "does this reach n8n" as the whole conversation
