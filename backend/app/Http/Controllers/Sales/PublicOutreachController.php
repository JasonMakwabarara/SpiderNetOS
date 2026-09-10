<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\PartnerProspect;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unauthenticated unsubscribe for partner outreach, keyed by the prospect's
 * invite token (carried in every email's footer and List-Unsubscribe header).
 * GET shows a confirm page (mail clients prefetch links, so GET never acts);
 * POST performs the opt-out, including RFC 8058 one-click posts.
 */
class PublicOutreachController extends Controller
{
    public function __construct(private readonly ProspectStateMachine $lifecycle) {}

    public function confirm(Request $request, string $token): Response
    {
        $prospect = $this->find($token);
        if ($prospect === null) {
            return $this->page('Link not recognised', 'This unsubscribe link is not valid or has already been used.', 404);
        }

        $action = htmlspecialchars($request->url(), ENT_QUOTES);
        $body = '<p>Click below and you will never receive another partner invitation from us.</p>'
            .'<form method="post" action="'.$action.'"><button type="submit" style="padding:10px 18px;font-size:15px;cursor:pointer;">Unsubscribe</button></form>';

        return $this->page('Unsubscribe from partner invitations', $body, 200);
    }

    public function unsubscribe(Request $request, string $token): Response
    {
        $prospect = $this->find($token);
        if ($prospect === null) {
            return $this->page('Link not recognised', 'This unsubscribe link is not valid or has already been used.', 404);
        }

        if ($prospect->status !== PartnerProspect::STATUS_UNSUBSCRIBED) {
            $this->lifecycle->optOut($prospect, 'unsubscribe_link', [
                'one_click' => $request->input('List-Unsubscribe') === 'One-Click',
                'ip' => $request->ip(),
            ]);
        }

        return $this->page('You are unsubscribed', '<p>Done. You will not hear from us about partnerships again.</p>', 200);
    }

    private function find(string $token): ?PartnerProspect
    {
        if (! preg_match('/^[A-Za-z0-9]{22}$/', $token)) {
            return null;
        }

        return PartnerProspect::where('invite_token', strtolower($token))->first();
    }

    private function page(string $title, string $body, int $status): Response
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>'.htmlspecialchars($title, ENT_QUOTES).'</title></head>'
            .'<body style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#1a1a1a;">'
            .'<h1 style="font-size:20px;">'.htmlspecialchars($title, ENT_QUOTES).'</h1>'.$body.'</body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
