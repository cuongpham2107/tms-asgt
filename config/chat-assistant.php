<?php

use App\Ai\Agents\DataAssistant;

return [
    /*
    |--------------------------------------------------------------------------
    | 是否启用
    |--------------------------------------------------------------------------
    */
    'enabled' => env('FILAMENT_CHAT_ASSISTANT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 界面配置
    |--------------------------------------------------------------------------
    |
    | width / height：首次打开时的默认尺寸（像素），用户拖拽后的尺寸会保存在
    | localStorage，点击「还原默认尺寸」会回到这里配置的值。
    |
    | fullscreen：是否允许最大化。
    | new_conversation：是否显示「开始新会话」按钮。
    |
    */
    'ui' => [
        'width' => env('FILAMENT_CHAT_ASSISTANT_WIDTH', 400),
        'height' => env('FILAMENT_CHAT_ASSISTANT_HEIGHT', 600),
        'fullscreen' => env('FILAMENT_CHAT_ASSISTANT_FULLSCREEN', true),
        'new_conversation' => env('FILAMENT_CHAT_ASSISTANT_NEW_CONVERSATION', true),

        /*
        |----------------------------------------------------------------------
        | 关闭状态图标
        |----------------------------------------------------------------------
        |
        | 支持 png / jpg 等图片。填写 public 下的相对路径（如 images/assistant.png）
        | 或完整 URL（如 https://cdn.example.com/assistant.png）；留空使用内置图标。
        |
        */
        'toggle_icon' => env('FILAMENT_CHAT_ASSISTANT_TOGGLE_ICON'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 请求超时
    |--------------------------------------------------------------------------
    |
    | 流式回复是单个长请求。PHP-FPM 默认 max_execution_time 为 30 秒，超时后
    | 抛出的是不可捕获的 FatalError，无法在对话框里提示；因此请求开始前会调用
    | set_time_limit() 放宽上限。0 表示不限制，通常保持略大于 Agent 的超时即可。
    |
    */
    'timeout' => (int) env('FILAMENT_CHAT_ASSISTANT_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | 是否在对话气泡中显示异常详情
    |--------------------------------------------------------------------------
    |
    | 开启后错误消息会附带异常类名与 message，便于本地排查；生产环境依赖
    | APP_DEBUG=false 自动关闭。
    |
    */
    'debug' => (bool) env('FILAMENT_CHAT_ASSISTANT_DEBUG', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | AI Agents
    |--------------------------------------------------------------------------
    |
    | 可配置一个或多个 Agent，对话框中可以在它们之间切换。
    | 每个 Agent 需实现 ChatAssistantAgent 并使用 RemembersConversations（见 README）。
    |
    | 平铺写法（所有面板共用）：
    |
    | 'agents' => [
    |     'order' => App\Ai\Agents\OrderSearch::class,
    |     'product' => App\Ai\Agents\ProductSearch::class,
    | ],
    |
    | 按面板分组（键为面板 ID，'*' 兜底未列出的面板）：
    |
    | 'agents' => [
    |     'backend' => ['order' => App\Ai\Agents\OrderSearch::class],
    |     'tenant' => ['product' => App\Ai\Agents\ProductSearch::class],
    |     '*' => ['order' => App\Ai\Agents\OrderSearch::class],
    | ],
    |
    | 数组键用于区分会话记录，可省略（省略时直接使用类名）。
    | 插件只在当前面板解析出至少一个 Agent 时挂载；分组写法下未列出且无 '*' 的面板不会出现对话框。
    |
    */
    'agents' => [
        'data_assistant' => DataAssistant::class,
    ],
];
