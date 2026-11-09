<?php

return [
    'title' => 'AI-助手',

    'open' => '打开 :name',
    'close' => '收起 :name',
    'collapse' => '收起',
    'maximize' => '最大化',
    'restore' => '还原',
    'reset_size' => '还原默认尺寸',

    'new_conversation' => '开始新会话',
    'switch_agent' => '切换助手',
    'empty_heading' => '开始对话',
    'empty_description' => '提出你的第一个问题。',
    'empty' => '你好，我是:name。有什么可以帮你？',

    'send' => '发送',
    'clear' => '清空',
    'scroll_to_bottom' => '滚动到底部',
    'placeholder' => '输入消息，Enter 发送，Shift + Enter 换行',
    'message_aria' => '消息内容',

    'thinking' => '正在思考…',
    'retry' => '重试',

    'status_idle' => '就绪',
    'status_error' => '上次回复失败，请重试',

    'copy' => '复制',
    'copied' => '已复制',
    'edit' => '编辑',
    'retract' => '撤回',
    'retract_confirm' => '确定撤回这条消息吗？撤回后不会再参与后续对话。',
    'regenerate' => '重新生成',

    'error' => '出错了，请稍后重试。',
    'error_service' => 'AI 服务暂时不可用，请稍后再试。',

    'agent_missing' => '未配置有效的 AI Agent（需实现 ChatAssistantAgent 接口并提供 name/avatar），请检查 config/chat-assistant.php 中的 agents。',
    'agent_unavailable' => '未配置可用的 AI Agent，请在 config/chat-assistant.php 中设置 agents。',
];
