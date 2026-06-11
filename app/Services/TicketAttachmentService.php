<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketAttachmentService
{
    public const MAX_FILES = 5;

    public const MAX_FILE_KB = 10240;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'txt',
    ];

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<TicketAttachment>
     */
    public function storeForTicket(Ticket $ticket, User $user, array $files): array
    {
        return $this->store($ticket, null, $user, $files);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<TicketAttachment>
     */
    public function storeForReply(Ticket $ticket, TicketReply $reply, User $user, array $files): array
    {
        return $this->store($ticket, $reply, $user, $files);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validationRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => [
                'file',
                'max:'.self::MAX_FILE_KB,
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
            ],
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<TicketAttachment>
     */
    private function store(Ticket $ticket, ?TicketReply $reply, User $user, array $files): array
    {
        $files = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));

        if ($files === []) {
            return [];
        }

        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'attachments' => 'You may upload up to '.self::MAX_FILES.' files.',
            ]);
        }

        $stored = [];

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
            $filename = Str::uuid().'.'.$extension;
            $path = $file->storeAs(
                'ticket-attachments/'.$ticket->id,
                $filename,
                'local'
            );

            $stored[] = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_reply_id' => $reply?->id,
                'user_id' => $user->id,
                'original_name' => $file->getClientOriginalName(),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize() ?: 0,
            ]);
        }

        return $stored;
    }
}
