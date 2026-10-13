<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use BelongsToCompany;

    protected $table = 'messages';

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id','sender_user_id','body','attachment_key','attachment_name','attachment_mime','attachment_size','client_message_id','purged_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'purged_at' => 'datetime'];
    }
}
