<?php

return [
    // Model calls (and tool rounds) the assistant may make while answering ONE user
    // message. Bounds cost and stops a confused model from looping on tools forever.
    'max_steps' => (int) env('ASSISTANT_MAX_STEPS', 8),

    // Most recent messages sent to the model on each call. Older history is dropped (the
    // window always starts on a user message so a tool call is never separated from its result).
    'history_messages' => (int) env('ASSISTANT_HISTORY_MESSAGES', 40),

    // Images are only sent to the model for the most recent N user messages that have them;
    // older pictures stay visible in the chat but cost no tokens on later turns.
    'recent_image_messages' => 2,

    'max_tool_result_chars' => (int) env('ASSISTANT_MAX_TOOL_RESULT_CHARS', 9000),

    // Attachments
    'max_files_per_message' => 8,
    'max_file_mb' => (int) env('ASSISTANT_MAX_FILE_MB', 20),
    'max_extracted_chars' => (int) env('ASSISTANT_MAX_EXTRACTED_CHARS', 40000),
    'image_max_edge' => 1568,
];
