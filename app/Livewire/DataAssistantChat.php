<?php

namespace App\Livewire;

use App\Ai\Agents\DataAssistant;
use App\Ai\AnswerRenderer;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Popup chat "Trợ lý dữ liệu" nổi ở góc màn hình trong panel admin.
 */
class DataAssistantChat extends Component
{
    public const PERMISSION = 'Use:DataAssistant';

    public const MAX_QUESTION_LENGTH = 1000;

    private const SESSION_KEY = 'data_assistant.conversation_id';

    /**
     * Câu hỏi gợi ý ở màn hình chào, chia theo nhóm nghiệp vụ.
     *
     * @var list<array{topic: string, icon: string, question: string}>
     */
    public const SAMPLE_QUESTIONS = [
        ['topic' => 'Vận hành', 'icon' => 'heroicon-o-truck', 'question' => 'Hôm qua có bao nhiêu chuyến, xe nào chạy nhiều nhất?'],
        ['topic' => 'Xu hướng', 'icon' => 'heroicon-o-chart-bar', 'question' => 'Số chuyến theo ngày trong tháng này'],
        ['topic' => 'Bảng kê', 'icon' => 'heroicon-o-document-text', 'question' => 'Hàng ngoài nào tháng này chưa có cước hoặc chưa xuất bảng kê?'],
        ['topic' => 'Doanh thu', 'icon' => 'heroicon-o-banknotes', 'question' => 'So sánh doanh thu cước 6 tháng gần đây'],
        ['topic' => 'Khách hàng', 'icon' => 'heroicon-o-building-office-2', 'question' => 'Top 10 khách hàng theo cước tháng này'],
        ['topic' => 'Tài xế', 'icon' => 'heroicon-o-map-pin', 'question' => 'Lái xe nào bấm sai vị trí nhiều nhất tháng này?'],
        ['topic' => 'Điểm giao', 'icon' => 'heroicon-o-clock', 'question' => 'Điểm nào giao lâu nhất (từ lúc đến tới lúc giao xong)?'],
        ['topic' => 'Ca trực', 'icon' => 'heroicon-o-calendar-days', 'question' => 'Tài xế nào nhiều ca trực không chạy chuyến tháng này?'],
    ];

    public ?string $conversationId = null;

    public ?string $error = null;

    /**
     * super_admin luôn được dùng (Shield ở dự án này không cấp quyền qua Gate, nên quyền mới chưa tự gán cho super_admin).
     */
    public static function canUse(?User $user): bool
    {
        return $user !== null
            && ($user->hasRole(Utils::getSuperAdminName()) || $user->can(self::PERMISSION));
    }

    public function mount(): void
    {
        $id = session(self::SESSION_KEY);
        $this->conversationId = is_string($id) && $this->ownsConversation($id) ? $id : null;
    }

    public function ask(string $question): void
    {
        $user = $this->authorizedUser();
        $question = trim(Str::limit($question, self::MAX_QUESTION_LENGTH, ''));
        $this->error = null;

        if ($question === '') {
            return;
        }

        $limiterKey = 'data-assistant:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiterKey, $this->dailyLimit())) {
            $this->error = 'Bạn đã hỏi đủ '.$this->dailyLimit().' câu hôm nay. Vui lòng quay lại vào ngày mai.';

            return;
        }

        RateLimiter::hit($limiterKey, (int) now()->diffInSeconds(now()->endOfDay()) + 1);

        try {
            $agent = $this->conversationId
                ? (new DataAssistant)->continue($this->conversationId, as: $user)
                : (new DataAssistant)->forUser($user);

            $response = $agent->prompt($question);

            $this->conversationId = $response->conversationId;
            session([self::SESSION_KEY => $this->conversationId]);
        } catch (Throwable $exception) {
            Log::error('Trợ lý dữ liệu lỗi', ['user_id' => $user->id, 'exception' => $exception]);
            $this->error = blank(config('ai.providers.'.config('ai.default').'.key'))
                ? 'Trợ lý chưa được cấu hình API key. Vui lòng liên hệ quản trị hệ thống.'
                : 'Trợ lý tạm thời không phản hồi. Vui lòng thử lại sau ít phút.';
        }

        unset($this->messages, $this->conversations);
    }

    public function newConversation(): void
    {
        $this->authorizedUser();
        $this->conversationId = null;
        $this->error = null;
        session()->forget(self::SESSION_KEY);
        unset($this->messages);
    }

    public function openConversation(string $id): void
    {
        $this->authorizedUser();

        if (! $this->ownsConversation($id)) {
            return;
        }

        $this->conversationId = $id;
        $this->error = null;
        session([self::SESSION_KEY => $id]);
        unset($this->messages);
    }

    /**
     * @return Collection<int, array{role: string, html: string, suggestions: list<string>, time: string}>
     */
    #[Computed]
    public function messages(): Collection
    {
        if (! $this->conversationId || ! $this->ownsConversation($this->conversationId)) {
            return collect();
        }

        return ConversationMessage::query()
            ->where('conversation_id', $this->conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['role', 'content', 'created_at'])
            ->map(function (ConversationMessage $message): array {
                if ($message->role !== 'assistant') {
                    return ['role' => $message->role, 'html' => e($message->content), 'suggestions' => [], 'time' => $message->created_at?->format('H:i d/m') ?? ''];
                }

                ['body' => $body, 'suggestions' => $suggestions] = DataAssistant::splitSuggestions($message->content);

                return [
                    'role' => $message->role,
                    'html' => app(AnswerRenderer::class)->render($body),
                    'suggestions' => $suggestions,
                    'time' => $message->created_at?->format('H:i d/m') ?? '',
                ];
            });
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        $user = Auth::user();

        if (! $user) {
            return collect();
        }

        return Conversation::query()
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->latest('updated_at')
            ->limit(15)
            ->get(['id', 'title', 'updated_at']);
    }

    public function render(): View
    {
        return view('livewire.data-assistant-chat', [
            'canUse' => self::canUse(Auth::user()),
        ]);
    }

    private function authorizedUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User && self::canUse($user), 403);

        return $user;
    }

    private function ownsConversation(string $id): bool
    {
        $user = Auth::user();

        return $user !== null && Conversation::query()
            ->whereKey($id)
            ->where('participant_type', $user->getMorphClass())
            ->where('participant_id', $user->getKey())
            ->exists();
    }

    private function dailyLimit(): int
    {
        return max(1, (int) config('ai.assistant.daily_limit', 50));
    }
}
