<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Kitchen;

use Sofrexa\Core\{Auth, HttpError, I18n, Request, Response, View};

/** K1 kitchen TV (signed in or with the display token), K2/K3 chef views, polling and the ticket actions. */
final class KitchenController
{
    public function chef(Request $req): void
    {
        $station = in_array($req->str('s'), ['kitchen', 'bar', 'ready'], true) ? $req->str('s') : 'kitchen';
        [$tickets, $ov] = $this->data($station);
        View::page('kitchen/chef', ['tickets' => $tickets, 'ov' => $ov, 'station' => $station,
            'tvUrl' => Auth::can('kitchen.ready') ? $this->tvUrl($req) : null], 'layouts/bare');
    }

    public function tv(Request $req): void
    {
        $this->renderTv($req, '/kitchen', '/kitchen/tv');
    }

    /** The TV without a login: /kds/{token}. */
    public function display(Request $req): void
    {
        $this->guard($req);
        $this->renderTv($req, '/kds/' . $req->param('token'), '/kds/' . $req->param('token'));
    }

    /** Polling: the ticket cards (and counters) of a view, plus the ticket keys to spot new ones. */
    public function poll(Request $req): void
    {
        if ($req->param('token') !== '') {
            $this->guard($req);
        }
        $view = $req->str('view') === 'tv' ? 'tv' : 'm';
        $station = $this->station($req->str('s'), $view);
        [$tickets, $ov] = $this->data($station);
        Response::json(['ok' => true, 'html' => View::partial('kitchen/_tickets', ['tickets' => $tickets]),
            'badges' => $view === 'tv' ? View::partial('kitchen/_badges', ['ov' => $ov, 'station' => $station]) : null,
            'keys' => array_values(array_map(static fn(array $t): string => $t['key'] . '|' . $t['state'], $tickets)),
            'clock' => digits(date('H:i'))]);
    }

    /** start | ready | call | recall | line */
    public function act(Request $req): void
    {
        if ($req->param('token') !== '') {
            $this->guard($req);
        } elseif (!Auth::can('kitchen.ready')) {
            throw new HttpError(403, I18n::t('err.forbidden'));
        }
        $order = $req->str('order');
        $round = $req->int('round');
        $station = in_array($req->str('station'), Kitchen::STATIONS, true) ? $req->str('station') : 'kitchen';
        $msg = null;
        switch ($req->param('act')) {
            case 'start':
                Kitchen::start($order, $round, $station);
                break;
            case 'ready':
                Kitchen::ready($order, $round, $station);
                $msg = I18n::t('kds.done_ready');
                break;
            case 'call':
                Kitchen::callAgain($order, $round, $station);
                $msg = I18n::t('kds.done_call');
                break;
            case 'recall':
                Kitchen::recall($order, $round, $station);
                $msg = I18n::t('kds.undone');
                break;
            case 'line':
                $state = Kitchen::toggleLine($req->str('line'));
                Response::json(['ok' => true, 'state' => $state]);
            default:
                throw new HttpError(404);
        }
        Response::json(['ok' => true, 'message' => $msg, 'undo' => $req->param('act') === 'ready']);
    }

    public function renew(Request $req): void
    {
        Kitchen::displayToken(true);
        Response::json(['ok' => true]);
    }

    // ------------------------------------------------------------ helpers

    private function renderTv(Request $req, string $base, string $self): never
    {
        $station = $this->station($req->str('s'), 'tv');
        [$tickets, $ov] = $this->data($station);
        View::page('kitchen/tv', ['tickets' => $tickets, 'ov' => $ov, 'station' => $station, 'base' => $base, 'self' => $self], 'layouts/bare');
    }

    private function station(string $s, string $view): string
    {
        $allowed = $view === 'tv' ? ['kitchen', 'bar', 'all'] : ['kitchen', 'bar', 'ready'];
        return in_array($s, $allowed, true) ? $s : 'kitchen';
    }

    /** Tickets of a station view and the overview counters. */
    private function data(string $station): array
    {
        $all = Kitchen::tickets();
        $ov = Kitchen::overview($all);
        $tickets = array_values(array_filter($all, static fn(array $t): bool => match ($station) {
            'all' => true,
            'ready' => $t['state'] === 'ready',
            default => $t['station'] === $station,
        }));
        return [$tickets, $ov];
    }

    private function guard(Request $req): void
    {
        if (!Kitchen::checkToken($req->param('token'))) {
            throw new HttpError(404);
        }
    }

    private function tvUrl(Request $req): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/kds/' . Kitchen::displayToken();
    }
}
