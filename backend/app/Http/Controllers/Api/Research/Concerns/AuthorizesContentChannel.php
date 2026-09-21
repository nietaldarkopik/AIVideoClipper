<?php

namespace App\Http\Controllers\Api\Research\Concerns;

use App\Models\ContentChannel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait AuthorizesContentChannel
{
    /**
     * 404 rather than 403 on a foreign channel: whether another user's channel
     * exists is itself information, and the rest of this API treats foreign
     * resources the same way.
     */
    protected function authorizeChannel(Request $request, ContentChannel $channel): void
    {
        if ($channel->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Channel not found.');
        }
    }
}
