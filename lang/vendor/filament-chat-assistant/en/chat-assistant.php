<?php

return [
    'title' => 'AI Assistant',

    'open' => 'Open :name',
    'close' => 'Close :name',
    'collapse' => 'Collapse',
    'maximize' => 'Maximize',
    'restore' => 'Restore',
    'reset_size' => 'Reset size',

    'new_conversation' => 'New conversation',
    'switch_agent' => 'Switch assistant',
    'empty_heading' => 'Start a conversation',
    'empty_description' => 'Ask your first question.',
    'empty' => 'Hi, I am :name. How can I help?',

    'send' => 'Send',
    'clear' => 'Clear',
    'scroll_to_bottom' => 'Scroll to bottom',
    'placeholder' => 'Type a message. Enter to send, Shift + Enter for a new line.',
    'message_aria' => 'Message',

    'thinking' => 'Thinking...',
    'retry' => 'Retry',

    'status_idle' => 'Ready',
    'status_error' => 'Last reply failed. Please retry.',

    'copy' => 'Copy',
    'copied' => 'Copied',
    'edit' => 'Edit',
    'retract' => 'Delete',
    'retract_confirm' => 'Delete this message? It will no longer be part of the conversation.',
    'regenerate' => 'Regenerate',

    'error' => 'Something went wrong. Please try again.',
    'error_service' => 'The AI service is temporarily unavailable. Please try again later.',

    'agent_missing' => 'No valid AI agent configured. It must implement ChatAssistantAgent with name/avatar. Check agents in config/chat-assistant.php.',
    'agent_unavailable' => 'No available AI agent. Set chat-assistant.agents in config/chat-assistant.php.',
];
