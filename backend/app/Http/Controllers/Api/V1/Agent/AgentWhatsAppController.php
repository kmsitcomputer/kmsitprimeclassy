<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\AgentDeviceService;
use App\Support\WhatsAppNumber;
use Illuminate\Http\Request;

/**
 * An Agent manages ITS OWN WhatsApp device from the Agent dashboard (route group `role:agen`, nothing else reaches this class).
 * The Agent is always `$request->user()`: no parameter selects another Agent or a device, so cross-Agent access is not expressible.
 * No GOWA credential, QR source URL or full number is ever returned.
 */
class AgentWhatsAppController extends Controller
{
    public function __construct(private readonly AgentDeviceService $devices) {}

    public function show(Request $request)
    {
        return $this->ok($this->devices->status($request->user()));
    }

    public function createDevice(Request $request)
    {
        $this->devices->ensure($request->user());

        return $this->created($this->devices->status($request->user()));
    }

    public function qr(Request $request)
    {
        return $this->ok($this->devices->startQrPairing($request->user()));
    }

    public function pairCode(Request $request)
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        return $this->ok($this->devices->startCodePairing($request->user(), $data['phone']));
    }

    public function reconnect(Request $request)
    {
        return $this->ok(['device' => $this->devices->reconnect($request->user())]);
    }

    public function logout(Request $request)
    {
        return $this->ok(['device' => $this->devices->logout($request->user())]);
    }

    public function replace(Request $request)
    {
        // Replacing drops the paired session: it has to be a deliberate act, not an accidental click.
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->ok(['device' => $this->devices->replace($request->user())]);
    }

    /** This Agent's own delivery history (masked recipient, no message body). */
    public function messages(Request $request)
    {
        $rows = WhatsAppMessage::query()
            ->where('agent_id', $request->user()->id)
            ->latest('id')->limit(50)->get()
            ->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id, 'purpose' => $m->purpose, 'event' => $m->event, 'status' => $m->status,
                'recipient' => WhatsAppNumber::mask($m->recipient_phone), 'attempts' => $m->attempts,
                'skip_reason' => $m->skip_reason, 'error_class' => $m->error_class,
                'created_at' => $m->created_at, 'sent_at' => $m->sent_at,
            ]);

        return $this->ok($rows);
    }
}
