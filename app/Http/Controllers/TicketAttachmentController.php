<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function show(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $ticket);

        abort_unless($attachment->ticket_id === $ticket->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        if ($attachment->isImage()) {
            return Storage::disk($attachment->disk)->response(
                $attachment->path,
                $attachment->original_name,
                ['Content-Type' => $attachment->mime_type]
            );
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name
        );
    }
}
