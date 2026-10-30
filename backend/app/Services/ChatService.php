<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\DomainException;
use App\Events\ConversationEvent;
use App\Models\Conversation;
use App\Models\ManagedDevice;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ChatService
{
    public function __construct(private readonly PushService $push)
    {
    }

    public function conversationFor(User $user, int $id): Conversation
    {
        $c = Conversation::query()->findOrFail($id);
        if (!$c->hasParticipant($user->id)) {
            throw new DomainException('forbidden');
        }
        return $c;
    }

    /** Supervisor ↔ employee only, within the supervisor's teams. */
    public function start(User $supervisor, User $employee, ?string $subject): Conversation
    {
        if (!$supervisor->canSeeUser($employee)) {
            throw new DomainException('forbidden', 'Employee is outside your teams.');
        }
        $existing = Conversation::query()->whereHas('participants', fn ($q) => $q->where('users.id', $supervisor->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $employee->id))->first();
        if ($existing) {
            return $existing;
        }
        return DB::transaction(function () use ($supervisor, $employee, $subject) {
            $c = Conversation::query()->create(['subject' => $subject, 'created_by' => $supervisor->id]);
            $c->participants()->attach([$supervisor->id => ['company_id' => $c->company_id], $employee->id => ['company_id' => $c->company_id]]);
            return $c;
        });
    }

    public function send(Conversation $c, User $sender, ?string $body, ?UploadedFile $file, ?string $clientId, array $allowedTypes, int $maxMb): Message
    {
        $body = $body !== null ? trim($body) : null;
        if (($body === null || $body === '') && $file === null) {
            throw new DomainException('empty_message');
        }
        if ($clientId && ($dup = Message::query()->where('conversation_id', $c->id)->where('client_message_id', $clientId)->first())) {
            return $dup; // offline resend
        }
        $att = [];
        if ($file !== null) {
            $mime = (string) $file->getMimeType();   // sniffed from content, not the client's claim
            if (!in_array($mime, $allowedTypes, true)) {
                throw new DomainException('attachment_type_not_allowed');
            }
            if ($file->getSize() > $maxMb * 1024 * 1024) {
                throw new DomainException('attachment_too_large');
            }
            $att = ['attachment_key' => $file->store("companies/{$c->company_id}/chat", 'company_files'), 'attachment_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'attachment_mime' => $mime, 'attachment_size' => $file->getSize()];
        }
        $m = DB::transaction(function () use ($c, $sender, $body, $clientId, $att) {
            $m = Message::query()->create(['conversation_id' => $c->id, 'sender_user_id' => $sender->id, 'body' => $body, 'client_message_id' => $clientId] + $att);
            foreach ($c->participants()->where('users.id', '!=', $sender->id)->pluck('users.id') as $uid) {
                MessageReceipt::query()->create(['message_id' => $m->id, 'user_id' => $uid]);
            }
            $c->forceFill(['last_message_at' => now()])->save();
            return $m;
        });
        ConversationEvent::dispatchFor($c->company_id, $c->id, 'message', ['id' => $m->id]);
        foreach ($c->participants()->where('users.id', '!=', $sender->id)->pluck('users.id') as $uid) {
            ManagedDevice::query()->where('assigned_user_id', $uid)->where('approval_state', 'approved')->each(fn ($d) => $this->push->ping($d, 'chat'));
        }
        return $m;
    }

    public function page(Conversation $c, User $reader, int $afterId): array
    {
        $msgs = Message::query()->where('conversation_id', $c->id)->where('id', '>', $afterId)->whereNull('purged_at')->orderBy('id')->limit(100)->get();
        MessageReceipt::query()->whereIn('message_id', $msgs->pluck('id'))->where('user_id', $reader->id)->whereNull('delivered_at')->update(['delivered_at' => now()]);
        // Opening the thread = reading it (drives the dashboard's unread notice and the sender's "read" tick).
        MessageReceipt::query()->whereIn('message_id', $msgs->pluck('id'))->where('user_id', $reader->id)->whereNull('read_at')->update(['read_at' => now()]);
        $receipts = MessageReceipt::query()->whereIn('message_id', $msgs->pluck('id'))->get()->groupBy('message_id');
        $senders = User::query()->with('roles:id,name')->whereIn('id', $msgs->pluck('sender_user_id')->unique())->get()->keyBy('id');
        return $msgs->map(fn (Message $m) => [
            'id' => $m->id, 'sender_user_id' => $m->sender_user_id, 'body' => $m->body, 'created_at' => $m->created_at->toAtomString(),
            'sender_name' => $senders[$m->sender_user_id]?->name, 'sender_label' => isset($senders[$m->sender_user_id]) ? self::label($senders[$m->sender_user_id]) : null,
            'attachment' => $m->attachment_key ? ['name' => $m->attachment_name, 'mime' => $m->attachment_mime, 'size' => $m->attachment_size] : null,
            'delivered' => ($receipts[$m->id] ?? collect())->every(fn ($r) => $r->delivered_at !== null),
            'read' => ($receipts[$m->id] ?? collect())->every(fn ($r) => $r->read_at !== null),
        ])->all();
    }

    /** "Ravi Kumar · Supervisor": who sent it and in what role (company admin, supervisor, …). */
    public static function label(User $u): string
    {
        $role = $u->roles->first()?->name;
        return $role ? "{$u->name} · {$role}" : $u->name;
    }

    public function attachment(Message $m)
    {
        abort_if($m->attachment_key === null || $m->purged_at !== null, 404);
        return Storage::disk('company_files')->download($m->attachment_key, $m->attachment_name, ['Content-Type' => $m->attachment_mime, 'X-Content-Type-Options' => 'nosniff']);
    }
}
