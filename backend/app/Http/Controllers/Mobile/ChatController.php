<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatService;
use App\Services\PolicyService;
use Illuminate\Http\Request;

final class ChatController extends Controller
{
    public function index(Request $r)
    {
        $uid = $r->user()->id;
        return response()->json(Conversation::query()->whereHas('participants', fn ($q) => $q->where('users.id', $uid))
            ->orderByDesc('last_message_at')->limit(50)->get(['id', 'subject', 'last_message_at']));
    }

    public function messages(Request $r, int $id, ChatService $chat)
    {
        $conv = $chat->conversationFor($r->user(), $id);
        return response()->json($chat->page($conv, $r->user(), (int) $r->query('after', 0)));
    }

    public function send(Request $r, int $id, ChatService $chat, PolicyService $policies)
    {
        $conv = $chat->conversationFor($r->user(), $id);
        $v = $policies->currentSnapshot($this->device($r))->values;
        $data = $r->validate(['body' => 'nullable|string|max:4000', 'client_message_id' => 'nullable|uuid', 'attachment' => 'nullable|file']);
        $m = $chat->send($conv, $r->user(), $data['body'] ?? null, $r->file('attachment'), $data['client_message_id'] ?? null,
            $v['attachments.allowed_types'], $v['attachments.max_mb']);
        return response()->json(['id' => $m->id, 'created_at' => $m->created_at->toAtomString()], 201);
    }

    public function attachment(Request $r, int $id, int $messageId, ChatService $chat)
    {
        $conv = $chat->conversationFor($r->user(), $id);
        return $chat->attachment(Message::query()->where('conversation_id', $conv->id)->findOrFail($messageId));
    }
}
